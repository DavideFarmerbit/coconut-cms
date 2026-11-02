import type {FieldId} from './DraftIds.ts';
import type {FieldChoices} from './FieldChoices.ts';
import type {FieldState} from './FieldState.ts';

/**
 * One field object: what it was when loaded, what it is now, and the
 * choices its save needs. Replaced whole by each edit, never changed, so an
 * edit keeps the one it replaced to put back.
 */
export interface FieldRecord {
    readonly id: FieldId;
    /** As stored; null for one this draft added. */
    readonly was: FieldState | null;
    /** As it is; null for one this draft removed. */
    readonly now: FieldState | null;
    readonly choices: FieldChoices;
}
