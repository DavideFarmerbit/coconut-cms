/** One registered enum, as coconut-cms:schema.values describes it. */
export interface EnumDescription {
    /** What a field names it by. */
    readonly identifier: string;
    /** Its cases' values, in order: what a default picks from. */
    readonly values: readonly (string | number)[];
}

/** What coconut-cms:schema.values gives, each list in the order registered. */
export interface ValueList {
    readonly enums: readonly EnumDescription[];
    /** Each value object by identifier. */
    readonly valueObjects: readonly string[];
}
