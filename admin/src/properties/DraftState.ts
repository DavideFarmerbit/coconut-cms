import type {ValueDescription} from '../public/types/TypeDescription.ts';

/**
 * An EntityDraft's whole state at one moment, to go back to: what a session
 * edit keeps from before and after it. Its own copy: the draft changing
 * after never changes it.
 */
export interface DraftState {
    /** The working copy's values. */
    readonly working: Readonly<Record<string, unknown>>;
    /** Which paths were changed, and what each holds. */
    readonly changed: ReadonlyMap<string, ValueDescription>;
    /** Each restructured Collection's items' saved indexes, by the list in `working` they belong to. */
    readonly origins: ReadonlyMap<unknown[], readonly (number | null)[]>;
}
