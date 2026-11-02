import type {FieldRoles, FieldShape, RuleEntry} from '../SchemaDefinitions.ts';
import type {TypeId} from './DraftIds.ts';

/** What a field object is at one moment: as stored, or as it is now. Replaced whole, never changed. */
export interface FieldState {
    /** The type declaring it: moved up or down its chain, another. */
    readonly owner: TypeId;
    readonly name: string;
    /** What it holds. */
    readonly shape: FieldShape;
    readonly rules: readonly RuleEntry[];
    /** Its roles; null for none: it's open. */
    readonly permission: FieldRoles | null;
}
