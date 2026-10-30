import type {TypeChange} from '../types/TypeChange.ts';
import type {EntityChange} from './EntityChange.ts';
import type {EntityData} from './EntityData.ts';
import type {EntityPage, EntityQuery} from './EntityQuery.ts';
import type {EntityStore} from './EntityStore.ts';

/** Where a list is: reading a page, showing one, or failed to read it. */
export type ListState = 'loading' | 'ready' | 'failed';

/** Told that a list changed: a page read, turned, or read again. */
export type ListListener = () => void;

/**
 * A query's entities, a page at a time, through the store, with no DOM: a
 * list view shows it and turns its pages. It reads its page again when the
 * store tells of a change it may show: an entity of its type created, one it
 * shows updated or deleted. So a rename shows in every list. It reads it
 * again too when the types change for one it lists. dispose() of it when
 * its view goes.
 *
 *     const cars = new EntityList(context.entities, {type: 'shop:car', orderBy: [{path: 'name'}], limit: 20});
 *     cars.onChange(() => this.render(cars.items));
 */
export class EntityList {
    private page: EntityPage = {items: [], nextCursor: null};
    private currentState: ListState = 'loading';
    private failureMessage = '';
    /** The cursor of each page shown so far, the first's null: the last is the page shown. */
    private readonly cursors: (string | null)[] = [null];
    /** Counts the reads asked for: an answer to an older one, overtaken, is dropped. */
    private reads = 0;
    private readonly listeners: ListListener[] = [];
    private readonly storeListener = (change: EntityChange): void => this.storeChanged(change);
    private readonly typesListener = (change: TypeChange): void => this.typesChanged(change);

    /** @param query What it lists; its `after` is the list's to set. */
    public constructor(
        private readonly store: EntityStore,
        private readonly query: Omit<EntityQuery, 'after'>,
    ) {
        store.onChange(this.storeListener);
        store.types.onChange(this.typesListener);
        void this.read();
    }

    /** The page's entities, in order. */
    public get items(): readonly EntityData[] {
        return this.page.items;
    }

    /** Whether it's reading a page, showing one, or failed to read it. */
    public get state(): ListState {
        return this.currentState;
    }

    /** Why reading failed; empty unless failed. */
    public get failure(): string {
        return this.failureMessage;
    }

    /** Whether a page comes before the one shown. */
    public get hasPrevious(): boolean {
        return this.cursors.length > 1;
    }

    /** Whether a page comes after the one shown. */
    public get hasNext(): boolean {
        return this.page.nextCursor !== null;
    }

    /** Tells `listener` of every change. */
    public onChange(listener: ListListener): void {
        this.listeners.push(listener);
    }

    /** Stops telling `listener`. */
    public offChange(listener: ListListener): void {
        const index = this.listeners.indexOf(listener);
        if (index >= 0) {
            this.listeners.splice(index, 1);
        }
    }

    /** Shows the next page. */
    public next(): void {
        if (this.page.nextCursor !== null) {
            this.cursors.push(this.page.nextCursor);
            void this.read();
        }
    }

    /** Shows the page before. */
    public previous(): void {
        if (this.cursors.length > 1) {
            this.cursors.pop();
            void this.read();
        }
    }

    /** Reads the page shown again. */
    public reload(): void {
        void this.read();
    }

    /** Stops following the store. */
    public dispose(): void {
        this.store.offChange(this.storeListener);
        this.store.types.offChange(this.typesListener);
        this.listeners.length = 0;
    }

    /** Reads the page the last cursor starts. */
    private async read(): Promise<void> {
        const read = ++this.reads;
        const after = this.cursors[this.cursors.length - 1] ?? null;
        this.currentState = 'loading';
        this.emit();
        try {
            const page = await this.store.query({...this.query, ...(after === null ? {} : {after})});
            if (read !== this.reads) {
                return;
            }
            this.page = page;
            this.currentState = 'ready';
        } catch (failure) {
            if (read !== this.reads) {
                return;
            }
            this.failureMessage = failure instanceof Error ? failure.message : String(failure);
            this.currentState = 'failed';
        }
        this.emit();
    }

    /** Reads the page again when `change` may show in it. */
    private storeChanged(change: EntityChange): void {
        const shown = this.page.items.some((entity) => entity.id === (change.kind === 'deleted' ? change.id : change.entity.id));
        const ofType = change.kind !== 'deleted' && this.store.types.catalog.isA(change.entity.type, this.query.type);
        if (shown || ofType) {
            this.reload();
        }
    }

    /** Reads the page again when the types changed for one it lists: its type, or a subtype. */
    private typesChanged(change: TypeChange): void {
        if ([...change.affected].some((type) => change.next.isA(type, this.query.type) || change.previous.isA(type, this.query.type))) {
            this.reload();
        }
    }

    private emit(): void {
        for (const listener of [...this.listeners]) {
            listener();
        }
    }
}
