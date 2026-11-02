import type {FieldId, TypeId} from './DraftIds.ts';

/** Why a draft can't be saved yet: something to choose or fix first, on the type or field it names. */
export interface SaveProblem {
    readonly message: string;
    /** The type it's about; null when it's about a field only. */
    readonly type: TypeId | null;
    /** The field it's about; null when it's about a type. */
    readonly field: FieldId | null;
}
