import {DraftCollectionItems} from '../../properties/DraftCollectionItems.ts';
import {EntityDraft} from '../../properties/EntityDraft.ts';
import {NewValues} from '../../properties/NewValues.ts';
import {PropertyInfos} from '../../properties/PropertyInfos.ts';
import {ReadTree} from '../../properties/ReadTree.ts';
import {ApiFailure} from '../api/ApiFailure.ts';
import {ErrorCode} from '../api/ErrorCode.ts';
import type {CollectionItems} from '../properties/CollectionItems.ts';
import {EntityLabels} from '../properties/EntityLabels.ts';
import type {PropertyInfo} from '../properties/PropertyInfo.ts';
import type {PropertyStatus} from '../properties/PropertyStatus.ts';
import type {TypeCatalog} from '../types/TypeCatalog.ts';
import type {EntityData} from './EntityData.ts';
import type {SessionChange, SessionListener} from './SessionChange.ts';

/** Where a session is: reading its entity, ready to edit, or failed to read it. */
export type SessionState = 'loading' | 'ready' | 'failed';

/** What a session saves through: the store, creating or updating. */
export interface SessionSaver {
    /** Creates an entity of `type` from its defaults, `changes` applied. */
    add(type: string, changes: Readonly<Record<string, unknown>>): Promise<EntityData>;

    /** Applies `changes` to the entity `id`. */
    update(id: string, changes: Readonly<Record<string, unknown>>): Promise<EntityData>;

    /** Told that `session`, new until now, was saved and has an id. */
    created(session: EntitySession): void;
}

/**
 * One entity being edited, shared by every view of it; the EntityStore
 * gives it out. It has no DOM: it reads, changes and saves the entity, and
 * tells its views what changed. Paths are the API's: "chassis:weight",
 * "engine.horsepower", "wheels.1.size".
 *
 *     const hold = context.entities.edit(id);
 *     await hold.session.ready;
 *     hold.session.set('name', 'Alpha', this);
 *     await hold.session.save();
 */
export class EntitySession {
    /** Settles once the entity is read, or failed to be. */
    public readonly ready: Promise<void>;
    private currentState: SessionState = 'loading';
    private failureMessage = '';
    private entityId: string | null;
    private entityType: string;
    private catalog: TypeCatalog | undefined;
    private draft: EntityDraft | undefined;
    private infos: PropertyInfos | undefined;
    private readonly errors = new Map<string, string>();
    private entityError = '';
    private saving = false;
    private readonly listeners: SessionListener[] = [];

    /**
     * @param entity What it starts from once read: an entity saved, or, for a
     *        new one, its type and its defaults.
     */
    private constructor(
        private readonly saver: SessionSaver,
        type: string,
        id: string | null,
        entity: Promise<{readonly catalog: TypeCatalog; readonly values: Readonly<Record<string, unknown>>; readonly type: string}>,
    ) {
        this.entityType = type;
        this.entityId = id;
        this.ready = entity.then(({catalog, values, type: read}) => {
            this.catalog = catalog;
            this.entityType = read;
            this.start(values);
            this.currentState = 'ready';
        }, (failure: unknown) => {
            this.failureMessage = failure instanceof Error ? failure.message : String(failure);
            this.currentState = 'failed';
        }).finally(() => this.emit({kind: 'reloaded'}));
    }

    /** A session of the saved entity `entity` will give. */
    public static of(saver: SessionSaver, id: string, entity: Promise<EntityData>, catalog: Promise<TypeCatalog>): EntitySession {
        return new EntitySession(saver, '', id, Promise.all([entity, catalog]).then(([read, types]) => ({catalog: types, values: read.values, type: read.type})));
    }

    /** A session of a new entity of `type`, from its defaults: its first save creates it. */
    public static fresh(saver: SessionSaver, type: string, catalog: Promise<TypeCatalog>): EntitySession {
        return new EntitySession(saver, type, null, catalog.then((types) => {
            const description = types.get(type);
            if (description === undefined) {
                throw new Error(`There is no type "${type}" you may read.`);
            }

            return {catalog: types, values: description.defaults, type};
        }));
    }

    /** Whether it's reading its entity, ready, or failed to read it. */
    public get state(): SessionState {
        return this.currentState;
    }

    /** Why reading it failed; empty unless failed. */
    public get failure(): string {
        return this.failureMessage;
    }

    /** Its entity's id; null while new. */
    public get id(): string | null {
        return this.entityId;
    }

    /** Its entity's type. */
    public get type(): string {
        return this.entityType;
    }

    /** The types it was read with; undefined until ready. */
    public get types(): TypeCatalog | undefined {
        return this.catalog;
    }

    /** What its entity is called, from the values now; "New <type>" for a new one with no label yet. */
    public get label(): string {
        if (this.catalog === undefined || this.draft === undefined) {
            return this.entityId ?? `New ${this.entityType}`;
        }

        const label = EntityLabels.of(this.catalog, this.entityType, this.entityId ?? '', this.draft.values);

        return label === '' ? `New ${this.entityType}` : label;
    }

    /** Its values now, unsaved changes in; empty until ready. */
    public get values(): Readonly<Record<string, unknown>> {
        return this.draft?.values ?? {};
    }

    /** Whether anything differs from what was saved; a new entity always does. */
    public get isDirty(): boolean {
        return this.entityId === null || (this.draft?.isDirty ?? false);
    }

    /** Whether a save is under way. */
    public get isSaving(): boolean {
        return this.saving;
    }

    /** The last save's error about the whole entity; empty when there's none. */
    public get entityErrorMessage(): string {
        return this.entityError;
    }

    /** Tells `listener` of every change. */
    public onChange(listener: SessionListener): void {
        this.listeners.push(listener);
    }

    /** Stops telling `listener`. */
    public offChange(listener: SessionListener): void {
        const index = this.listeners.indexOf(listener);
        if (index >= 0) {
            this.listeners.splice(index, 1);
        }
    }

    /** The property at `path`, described; undefined when there's none. */
    public describe(path: string): PropertyInfo | undefined {
        return this.infos?.of(path);
    }

    /** The value at `path` now. */
    public valueAt(path: string): unknown {
        return this.draft === undefined ? undefined : ReadTree.at(this.draft.values, path);
    }

    /** Sets the value at `path`, `source` the view setting it. Its error, from the last save, is answered. */
    public set(path: string, value: unknown, source: object | null = null): void {
        const info = this.describe(path);
        if (this.draft === undefined || info === undefined) {
            return;
        }

        this.errors.delete(path);
        this.draft.set(path, value, info.value);
        this.emit({kind: 'values', paths: [path], source});
        this.emit({kind: 'state'});
    }

    /** Sets the value at `path` and tells it as a change of shape: an owned object's class picked, emptied. */
    public replace(path: string, value: unknown, source: object | null = null): void {
        const info = this.describe(path);
        if (this.draft === undefined || info === undefined) {
            return;
        }

        this.errors.delete(path);
        this.draft.set(path, value, info.value);
        this.emit({kind: 'shape', path, source});
        this.emit({kind: 'state'});
    }

    /** The items of the Collection at `path`, changed one operation at a time; undefined when it isn't one. */
    public itemsAt(path: string, source: object | null = null): CollectionItems | undefined {
        const info = this.describe(path);
        const draft = this.draft;
        if (draft === undefined || info?.value.kind !== 'collection') {
            return undefined;
        }

        const slot = {get: () => this.valueAt(path), set: (next: unknown) => draft.set(path, next, info.value)};

        return new DraftCollectionItems(draft, slot, path, info.value, () => {
            this.errors.delete(path);
            this.emit({kind: 'shape', path, source});
            this.emit({kind: 'state'});
        });
    }

    /** Where the property at `path` stands against what was saved; for a new entity, against its defaults. */
    public statusAt(path: string): PropertyStatus {
        return this.draft?.statusAt(path) ?? {isNew: false, movedFrom: null, isChanged: false};
    }

    /** The last save's error at `path`; undefined when there's none. */
    public errorAt(path: string): string | undefined {
        return this.errors.get(path);
    }

    /** What a new object holds at `path`; undefined when nothing does: a Collection's item. */
    public defaultAt(path: string): unknown {
        const info = this.describe(path);
        if (info === undefined || info.field === null || info.holderType === null) {
            return undefined;
        }

        return this.catalog?.get(info.holderType)?.defaults[info.field];
    }

    /** Puts back what was saved at `path`; for a new entity, its default. */
    public resetAt(path: string, source: object | null = null): void {
        this.draft?.resetAt(path);
        this.errors.delete(path);
        this.emit({kind: 'shape', path, source});
        this.emit({kind: 'state'});
    }

    /** Puts what a new object holds at `path`; an owned object keeps its id, only its values reset. */
    public resetToDefaultAt(path: string, source: object | null = null): void {
        const value = this.defaultAt(path);
        if (value !== undefined) {
            this.replace(path, NewValues.keepingIds(value, this.valueAt(path)), source);
        }
    }

    /** Drops every change. */
    public revert(): void {
        this.draft?.revert();
        this.clearErrors();
        this.emit({kind: 'reloaded'});
    }

    /**
     * Sends every change: an update, or, for a new entity, a create. Then
     * shows what was stored. A failed save keeps the changes and puts its
     * errors at their paths.
     * @return Whether it saved.
     */
    public async save(): Promise<boolean> {
        const draft = this.draft;
        const catalog = this.catalog;
        if (draft === undefined || catalog === undefined || this.saving || !this.isDirty) {
            return !this.isDirty;
        }

        this.saving = true;
        this.emit({kind: 'state'});
        try {
            const changes = draft.changes(catalog);
            const saved = this.entityId === null ? await this.saver.add(this.entityType, changes) : await this.saver.update(this.entityId, changes);
            const wasNew = this.entityId === null;
            this.entityId = saved.id;
            this.entityType = saved.type;
            this.clearErrors();
            this.start(saved.values);
            if (wasNew) {
                this.saver.created(this);
            }

            return true;
        } catch (failure) {
            this.showFailure(failure);

            return false;
        } finally {
            this.saving = false;
            this.emit({kind: 'reloaded'});
        }
    }

    /** Starts over from `values`, what was stored. */
    private start(values: Readonly<Record<string, unknown>>): void {
        const draft = new EntityDraft(values);
        const catalog = this.catalog;
        this.draft = draft;
        this.infos = catalog === undefined ? undefined : new PropertyInfos(catalog, this.entityType, () => draft.values);
    }

    /** Puts `failure`'s errors at their paths, or on the whole entity. */
    private showFailure(failure: unknown): void {
        this.clearErrors();
        if (!(failure instanceof ApiFailure) || (failure.code !== ErrorCode.InvalidInput && failure.code !== ErrorCode.ValidationFailed)) {
            this.entityError = failure instanceof Error ? failure.message : String(failure);

            return;
        }

        const errors = failure.details['errors'];
        const byPath = typeof errors === 'object' && errors !== null ? errors as Record<string, unknown> : {};
        for (const [path, messages] of Object.entries(byPath)) {
            const message = Array.isArray(messages) ? messages.join(' ') : String(messages);
            const property = path.startsWith('changes.') ? path.slice('changes.'.length) : path;
            if (property === '' || property === 'changes') {
                this.entityError = [this.entityError, message].filter((each) => each !== '').join(' ');
            } else {
                this.errors.set(property, message);
            }
        }
        if (this.errors.size === 0 && this.entityError === '') {
            this.entityError = failure.message;
        }
    }

    private clearErrors(): void {
        this.errors.clear();
        this.entityError = '';
    }

    private emit(change: SessionChange): void {
        for (const listener of [...this.listeners]) {
            listener(change);
        }
    }
}
