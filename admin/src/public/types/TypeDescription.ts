/** A field's kind, as coconut-cms:types.get names it. */
export type ValueKind = 'scalar' | 'value_object' | 'embed' | 'reference' | 'owning_reference' | 'collection';

/** What a field holds, as coconut-cms:types.get describes it. */
export interface ValueDescription {
    readonly kind: ValueKind;
    readonly nullable: boolean;
    readonly scalarType: string | null;
    readonly enumValues: readonly (string | number)[] | null;
    /** The entity type an Embed, Reference or owned object holds; a value object's registered identifier. */
    readonly target: string | null;
    readonly item: ValueDescription | null;
}

/** One field, and what the caller may do with its value. */
export interface FieldDescription {
    readonly read: boolean;
    readonly write: boolean;
    readonly value: ValueDescription;
}

/** One entity type, as coconut-cms:types.get and types.list give it. */
export interface TypeDescription {
    readonly identifier: string;
    readonly parent: string | null;
    readonly editorExtensible: boolean;
    readonly may: Readonly<Record<'read' | 'create' | 'update' | 'delete', boolean>>;
    /** Its own fields, by name, in order. */
    readonly fields: Readonly<Record<string, FieldDescription>>;
    /** What a new entity of it holds, by field, inherited fields included, in the form a read gives. */
    readonly defaults: Readonly<Record<string, unknown>>;
}

/** What coconut-cms:types.list gives. */
export interface TypeList {
    readonly types: readonly TypeDescription[];
}
