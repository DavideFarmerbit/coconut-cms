import {TypeChanges} from '../../types/TypeChanges.ts';
import type {ApiClient} from '../api/ApiClient.ts';
import type {TypeChange, TypeListener} from './TypeChange.ts';
import {TypeCatalog} from './TypeCatalog.ts';
import type {TypeList} from './TypeDescription.ts';

/**
 * The entity types the user may read, one store per admin, every view
 * reaching it as `context.types`. Its catalog is an immutable snapshot; when
 * the types change, the schema editor reloads the store, which swaps the
 * snapshot and tells its listeners what changed. A view reads `catalog`
 * when it shows something, and never keeps one.
 *
 *     await context.types.ready;
 *     this.show(context.types.catalog);
 *     context.types.onChange(() => this.show(context.types.catalog));
 */
export class TypeStore {
    /** Settles once the first catalog is read; fails when it can't be. */
    public readonly ready: Promise<void>;
    private current = TypeCatalog.of([]);
    /** Counts the reads asked for: an answer to an older one, overtaken, is dropped. */
    private reads = 0;
    private readonly listeners: TypeListener[] = [];

    public constructor(private readonly api: ApiClient) {
        this.ready = this.read(false).then(() => undefined);
    }

    /** The types now; none until ready. */
    public get catalog(): TypeCatalog {
        return this.current;
    }

    /** Tells `listener` of every change of the types. */
    public onChange(listener: TypeListener): void {
        this.listeners.push(listener);
    }

    /** Stops telling `listener`. */
    public offChange(listener: TypeListener): void {
        const index = this.listeners.indexOf(listener);
        if (index >= 0) {
            this.listeners.splice(index, 1);
        }
    }

    /**
     * Reads the types again: after a migration. When they changed, swaps the
     * catalog and tells the listeners.
     * @return What changed; null when nothing did, or a later reload overtook this one.
     */
    public async reload(): Promise<TypeChange | null> {
        await this.ready.catch(() => undefined);

        return this.read(true);
    }

    /**
     * Reads the types, and makes them the catalog.
     * @param tell Whether listeners hear of it: not for the first read, which nothing was shown before.
     */
    private async read(tell: boolean): Promise<TypeChange | null> {
        const read = ++this.reads;
        const list = await this.api.call<TypeList>('coconut-cms:types.list');
        if (read !== this.reads) {
            return null;
        }

        // The snapshot is kept while nothing changed: the same catalog means the same types.
        const change = TypeChanges.between(this.current, TypeCatalog.of(list.types));
        if (change === null) {
            return null;
        }

        this.current = change.next;
        if (tell) {
            for (const listener of [...this.listeners]) {
                listener(change);
            }
        }

        return change;
    }
}
