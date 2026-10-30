import type {TypeCatalog} from './TypeCatalog.ts';

/** What the TypeStore tells its listeners when the types changed. */
export interface TypeChange {
    /** The catalog before. */
    readonly previous: TypeCatalog;
    /** The catalog now. */
    readonly next: TypeCatalog;
    /** The types whose description differs, new or gone included. */
    readonly changed: ReadonlySet<string>;
    /**
     * The types changed, their subtypes, and every type holding an affected
     * type, embedded, owned or as a Collection's items: whose entities may
     * read differently now.
     */
    readonly affected: ReadonlySet<string>;
}

/** Told of each TypeChange. */
export type TypeListener = (change: TypeChange) => void;
