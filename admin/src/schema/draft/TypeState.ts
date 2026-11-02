import type {RuleEntry, TypeRoles} from '../SchemaDefinitions.ts';
import type {FieldId, TypeId} from './DraftIds.ts';
import type {PinnedDefault} from './PinnedDefault.ts';

/** What a type object is at one moment: as stored, or as it is now. Replaced whole, never changed. */
export interface TypeState {
    readonly identifier: string;
    /** The type it extends; null for a root. */
    readonly parent: TypeId | null;
    readonly editorExtensible: boolean;
    /** Its own fields, in order. */
    readonly fields: readonly FieldId[];
    /** The defaults it sets itself, by their path's key; a path left out follows its ancestors. */
    readonly defaults: ReadonlyMap<string, PinnedDefault>;
    readonly prototypeRules: readonly RuleEntry[];
    readonly typePermission: TypeRoles | null;
    /** Each unique group, over its own fields. */
    readonly uniqueGroups: readonly (readonly FieldId[])[];
}
