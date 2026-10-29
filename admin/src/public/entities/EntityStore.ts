import type {ApiClient} from '../api/ApiClient.ts';
import {EntityLabels} from '../properties/EntityLabels.ts';
import type {ReferenceValue} from '../properties/RecordValues.ts';
import type {TypeCatalog} from '../types/TypeCatalog.ts';
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
 *     const hold = context.entities.edit(id);
 *     // ...show hold.session...
 *     const closed = await hold.release();
 */
export class EntityStore implements SessionSaver, HoldReleaser {
    /** What was read, by id. */
    private readonly read = new Map<string, Promise<EntityData>>();
    /** The sessions given out, by entity id. */
    private readonly sessions = new Map<string, Held>();
    /** The sessions of entities not saved yet: nothing finds them by id. */
    private readonly fresh = new Map<EntitySession, Held>();
    private readonly listeners: EntityListener[] = [];

    /**
     * @param catalog Gives the types the user may read: what sessions and labels work with.
     * @param unsaved Asked when the last hold on a session with unsaved changes is released.
     */
    public constructor(
        private readonly api: ApiClient,
        private readonly catalog: () => Promise<TypeCatalog>,
        private readonly unsaved: UnsavedQuestion,
    ) {
    }

    /** Whether any session has unsaved changes: leaving the page asks first then. */
    public get hasUnsaved(): boolean {
        return [...this.sessions.values(), ...this.fresh.values()].some(({session}) => session.isDirty);
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
            entity = this.api.call<EntityData>('coconut-cms:entities.get', {id});
            this.read.set(id, entity);
            // A failed read isn't kept: the next asks again.
            entity.catch(() => this.read.delete(id));
        }

        return entity;
    }

    /** A page of `query`'s entities; each is kept as read. */
    public async query(query: EntityQuery): Promise<EntityPage> {
        const page = await this.api.call<EntityPage>('coconut-cms:entities.query', {...query});
        for (const entity of page.items) {
            this.read.set(entity.id, Promise.resolve(entity));
        }

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
        this.read.set(entity.id, Promise.resolve(entity));
        this.emit({kind: 'created', entity});

        return entity;
    }

    /** Applies `changes` to the entity `id`, and tells the listeners. */
    public async update(id: string, changes: Readonly<Record<string, unknown>>): Promise<EntityData> {
        const entity = await this.api.call<EntityData>('coconut-cms:entities.update', {id, changes});
        this.read.set(entity.id, Promise.resolve(entity));
        this.emit({kind: 'updated', entity});

        return entity;
    }

    /** Deletes the entity `id`, and tells the listeners. */
    public async delete(id: string): Promise<void> {
        await this.api.call('coconut-cms:entities.delete', {id});
        this.read.delete(id);
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
