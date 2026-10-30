import type {ApiClient} from '../api/ApiClient.ts';
import {EntityLabels} from '../properties/EntityLabels.ts';
import type {ReferenceValue} from '../properties/RecordValues.ts';
import {TypeChanges} from '../../types/TypeChanges.ts';
import type {TypeCatalog} from '../types/TypeCatalog.ts';
import type {TypeChange} from '../types/TypeChange.ts';
import type {TypeStore} from '../types/TypeStore.ts';
import type {EntityChange, EntityListener} from './EntityChange.ts';
import type {EntityData} from './EntityData.ts';
import {EntityHold, type HoldReleaser} from './EntityHold.ts';
import type {EntityPage, EntityQuery} from './EntityQuery.ts';
import {EntitySession, type SessionSaver} from './EntitySession.ts';
import type {UnsavedQuestion} from './UnsavedQuestion.ts';

/** A session given out, and the holds on it. */
interface Held {
    readonly session: EntitySession;
    readonly holds: Set<EntityHold>;
}

/**
 * The entities the admin works with, one store per admin, every view
 * reaching it as `context.entities`. It reads, queries, creates, updates and
 * deletes them through the API, keeps what it read, and tells its listeners
 * of each change, so a list or a label follows a save.
 *
 * It gives out sessions to edit them: one per entity, shared by every view
 * holding it, freed when the last hold is released. Releasing the last hold
 * on a session with unsaved changes asks first.
 *
 * It follows the types: when they change, it forgets what it read of the
 * types affected, and every session of one without edits reads its entity
 * again. settle() asks about the sessions with edits before a change.
 *
 *     const hold = context.entities.edit(id);
 *     // ...show hold.session...
 *     const closed = await hold.release();
 */
export class EntityStore implements SessionSaver, HoldReleaser {
    /** What was read, by id. */
    private readonly read = new Map<string, Promise<EntityData>>();
    /** The type of each entity read, once known, by id. */
    private readonly readTypes = new Map<string, string>();
    /** The sessions given out, by entity id. */
    private readonly sessions = new Map<string, Held>();
    /** The sessions of entities not saved yet: nothing finds them by id. */
    private readonly fresh = new Map<EntitySession, Held>();
    private readonly listeners: EntityListener[] = [];

    /**
     * @param types The types the user may read: what sessions, labels and lists work with.
     * @param unsaved Asked when the last hold on a session with unsaved changes is released, and by settle().
     */
    public constructor(
        private readonly api: ApiClient,
        public readonly types: TypeStore,
        private readonly unsaved: UnsavedQuestion,
    ) {
        types.onChange((change) => this.typesChanged(change));
    }

    /** Whether any session has unsaved changes: leaving the page asks first then. */
    public get hasUnsaved(): boolean {
        return this.allHeld().some(({session}) => session.isDirty);
    }

    /**
     * Settles the sessions with edits whose type a change of `types` affects:
     * the types, their subtypes, the types holding them. Asks Save, Discard or
     * Cancel for each; what the schema editor asks before it changes types.
     * @return Whether every one was settled: false once one is cancelled, or fails to save.
     */
    public async settle(types: Iterable<string>): Promise<boolean> {
        const affected = TypeChanges.affectedBy(new Set(types), [this.types.catalog]);
        for (const {session} of this.allHeld()) {
            if (!session.hasEdits || !affected.has(session.type)) {
                continue;
            }

            const answer = await this.unsaved.ask(session.label);
            if (answer === 'cancel' || (answer === 'save' && !await session.save())) {
                return false;
            }
            if (answer === 'discard') {
                session.revert();
            }
        }

        return true;
    }

    /** Tells `listener` of every entity created, updated or deleted. */
    public onChange(listener: EntityListener): void {
        this.listeners.push(listener);
    }

    /** Stops telling `listener`. */
    public offChange(listener: EntityListener): void {
        const index = this.listeners.indexOf(listener);
        if (index >= 0) {
            this.listeners.splice(index, 1);
        }
    }

    /** The entity `id`, as last read or saved; read once. */
    public get(id: string): Promise<EntityData> {
        let entity = this.read.get(id);
        if (entity === undefined) {
            const reading = this.api.call<EntityData>('coconut-cms:entities.get', {id});
            entity = reading;
            this.read.set(id, reading);
            reading.then((read) => {
                if (this.read.get(id) === reading) {
                    this.readTypes.set(id, read.type);
                }
            }, () => {
                // A failed read isn't kept: the next asks again.
                if (this.read.get(id) === reading) {
                    this.read.delete(id);
                }
            });
        }

        return entity;
    }

    /** A page of `query`'s entities; each is kept as read. */
    public async query(query: EntityQuery): Promise<EntityPage> {
        const page = await this.api.call<EntityPage>('coconut-cms:entities.query', {...query});
        page.items.forEach((entity) => this.keep(entity));

        return page;
    }

    /** What `reference`'s target is called; its id when it can't be read. */
    public async labelOf(reference: ReferenceValue): Promise<string> {
        try {
            const [entity, catalog] = await Promise.all([this.get(reference.id), this.catalog()]);

            return EntityLabels.of(catalog, entity.type, entity.id, entity.values);
        } catch {
            return reference.id;
        }
    }

    /** A hold on the session of the entity `id`: the one given out already, or a new one. */
    public edit(id: string): EntityHold {
        let held = this.sessions.get(id);
        if (held === undefined) {
            held = {session: EntitySession.of(this, id, this.get(id), this.catalog()), holds: new Set()};
            this.sessions.set(id, held);
        }

        return this.hold(held);
    }

    /** A hold on the session of a new entity of `type`, from its defaults: its first save creates it. */
    public create(type: string): EntityHold {
        const held: Held = {session: EntitySession.fresh(this, type, this.catalog()), holds: new Set()};
        this.fresh.set(held.session, held);

        return this.hold(held);
    }

    /** Creates an entity of `type` from its defaults, `changes` applied, and tells the listeners. */
    public async add(type: string, changes: Readonly<Record<string, unknown>>): Promise<EntityData> {
        const entity = await this.api.call<EntityData>('coconut-cms:entities.create', {type, changes});
        this.keep(entity);
        this.emit({kind: 'created', entity});

        return entity;
    }

    /** Applies `changes` to the entity `id`, and tells the listeners. */
    public async update(id: string, changes: Readonly<Record<string, unknown>>): Promise<EntityData> {
        const entity = await this.api.call<EntityData>('coconut-cms:entities.update', {id, changes});
        this.keep(entity);
        this.emit({kind: 'updated', entity});

        return entity;
    }

    /** Deletes the entity `id`, and tells the listeners. */
    public async delete(id: string): Promise<void> {
        await this.api.call('coconut-cms:entities.delete', {id});
        this.read.delete(id);
        this.readTypes.delete(id);
        this.emit({kind: 'deleted', id});
    }

    /** Files `session`, new until its first save, under its id: from now on edit() finds it. */
    public created(session: EntitySession): void {
        const held = this.fresh.get(session);
        if (held !== undefined && session.id !== null) {
            this.fresh.delete(session);
            this.sessions.set(session.id, held);
        }
    }

    /**
     * Lets `hold` go. The last hold on a session with unsaved changes asks
     * first: saving, discarding, or cancelling, which keeps the hold.
     * @return Whether it was let go.
     */
    public async release(hold: EntityHold): Promise<boolean> {
        const held = this.heldOf(hold.session);
        if (held === undefined || !held.holds.has(hold)) {
            return true;
        }

        // Taken out before asking: two holds released at once never both think the other stays.
        held.holds.delete(hold);
        if (held.holds.size > 0 || !held.session.isDirty) {
            this.forget(held);

            return true;
        }

        const answer = await this.unsaved.ask(held.session.label);
        if (answer === 'cancel' || (answer === 'save' && !await held.session.save())) {
            held.holds.add(hold);

            return false;
        }

        this.forget(held);

        return true;
    }

    /** The types now, once read. */
    private async catalog(): Promise<TypeCatalog> {
        await this.types.ready;

        return this.types.catalog;
    }

    /** Keeps `entity` as read. */
    private keep(entity: EntityData): void {
        this.read.set(entity.id, Promise.resolve(entity));
        this.readTypes.set(entity.id, entity.type);
    }

    /**
     * Follows a change of the types: forgets what it read of the types
     * affected, and of what's still being read; every session of a type
     * affected without edits reads its entity again. A session with edits
     * keeps them as they are.
     */
    private typesChanged(change: TypeChange): void {
        for (const id of [...this.read.keys()]) {
            const type = this.readTypes.get(id);
            if (type === undefined || change.affected.has(type)) {
                this.read.delete(id);
                this.readTypes.delete(id);
            }
        }

        for (const {session} of this.allHeld()) {
            if (change.affected.has(session.type) && !session.hasEdits) {
                void session.refresh(change.next, session.id === null ? null : this.get(session.id));
            }
        }
    }

    /** Every session given out. */
    private allHeld(): Held[] {
        return [...this.sessions.values(), ...this.fresh.values()];
    }

    /** A new hold on `held`'s session. */
    private hold(held: Held): EntityHold {
        const hold = new EntityHold(held.session, this);
        held.holds.add(hold);

        return hold;
    }

    /** The record of `session`, given out by id or new. */
    private heldOf(session: EntitySession): Held | undefined {
        return this.fresh.get(session) ?? (session.id === null ? undefined : this.sessions.get(session.id));
    }

    /** Frees `held`'s session once no hold is left on it. */
    private forget(held: Held): void {
        if (held.holds.size > 0) {
            return;
        }

        this.fresh.delete(held.session);
        if (held.session.id !== null && this.sessions.get(held.session.id) === held) {
            this.sessions.delete(held.session.id);
        }
    }

    private emit(change: EntityChange): void {
        for (const listener of [...this.listeners]) {
            listener(change);
        }
    }
}
