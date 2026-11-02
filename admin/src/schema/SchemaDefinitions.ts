import type {ValueKind} from '../public/types/TypeDescription.ts';

/** What a field holds, as addField and retypeField take it and schema.get gives it: without its name. */
export interface FieldShape {
    readonly kind: ValueKind;
    readonly nullable: boolean;
    /** A Scalar's type. */
    readonly scalarType?: string;
    /** An enum Scalar's enum, by its registered identifier. */
    readonly enum?: string;
    /** An Embed's, Reference's or owned object's type; a value object's registered identifier. */
    readonly target?: string;
    /** A Collection's items. */
    readonly item?: FieldShape;
}

/** A validator rule as a save takes it: its identifier and its parameters. */
export interface RuleEntry {
    readonly rule: string;
    readonly config: Readonly<Record<string, unknown>>;
}

/** The roles a field asks to be read and written; null leaves it open. */
export interface FieldRoles {
    readonly read: string | null;
    readonly write: string | null;
}

/** The role a type asks per action on its entities; null leaves it to its ancestors. */
export interface TypeRoles {
    readonly read: string | null;
    readonly create: string | null;
    readonly update: string | null;
    readonly delete: string | null;
}

/** One type, as coconut-cms:schema.get gives it. */
export interface TypeDefinition {
    readonly identifier: string;
    /** "native" for one the code declares, "editor" for one the editor made: only that one changes. */
    readonly source: 'native' | 'editor';
    readonly parent: string | null;
    readonly editorExtensible: boolean;
    /** Its own fields, by name, in order. */
    readonly fields: Readonly<Record<string, FieldShape>>;
    /** The defaults it sets itself, by path, in stored form. */
    readonly defaults: Readonly<Record<string, unknown>>;
    readonly fieldRules: Readonly<Record<string, readonly RuleEntry[]>>;
    readonly fieldPermissions: Readonly<Record<string, FieldRoles>>;
    readonly prototypeRules: readonly RuleEntry[];
    readonly typePermission: TypeRoles | null;
    /** Each unique group over its own fields, by name. */
    readonly uniqueGroups: readonly (readonly string[])[];
}

/** What coconut-cms:schema.get gives. */
export interface SchemaDefinitionList {
    readonly revision: number;
    readonly types: readonly TypeDefinition[];
}
