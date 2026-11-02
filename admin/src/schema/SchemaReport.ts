import type {SchemaOperationData} from './draft/SchemaOperationData.ts';

/** A break a save causes on another editor-made type. */
export interface SchemaBreak {
    readonly type: string;
    readonly description: string;
    /** The fixup setting it aside, described; null when the policy blocks it. */
    readonly setAside: string | null;
}

/** Values a unique group would hold twice. */
export interface Duplicate {
    /** The type declaring the group. */
    readonly type: string;
    /** The group's values, by column. */
    readonly values: Readonly<Record<string, unknown>>;
    /** The id of every entry holding them. */
    readonly entities: readonly string[];
}

/** A warning, never refusing: a type's default entry breaks one of its rules. */
export interface DefaultBreak {
    readonly type: string;
    /** Where, as a validation error names it; "" for the entry itself. */
    readonly path: string;
    readonly error: string;
}

/** One entry the save changes, shown by a preview: what changes in it, by path. */
export interface EntrySample {
    readonly id: string;
    /** Its type once moved. */
    readonly type: string;
    /** By the paths it had. */
    readonly before: Readonly<Record<string, unknown>>;
    /** By the paths it has after. */
    readonly after: Readonly<Record<string, unknown>>;
}

/** What coconut-cms:schema.preview and schema.save answer: what the save runs, and what it finds. */
export interface SchemaReport {
    /** The schema revision now: the new one, once saved. */
    readonly revision: number;
    readonly operations: readonly SchemaOperationData[];
    /** Every fixup it runs, described, in order. */
    readonly fixups: readonly string[];
    readonly breaks: readonly SchemaBreak[];
    /** Every break the policy blocks, described: the save is refused. */
    readonly blocked: readonly string[];
    /** Why each entry that can't move can't, by its id: the save is refused. */
    readonly failures: Readonly<Record<string, string>>;
    /** Values a unique group would hold twice: the save is refused. */
    readonly duplicates: readonly Duplicate[];
    /** How many entries moved: 0 for a preview. */
    readonly moved: number;
    readonly defaultBreaks: readonly DefaultBreak[];
    /** A preview's: the first entries that change, in id order. */
    readonly samples: readonly EntrySample[];
}
