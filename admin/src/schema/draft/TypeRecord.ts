import type {TypeId} from './DraftIds.ts';
import type {TypeChoices} from './TypeChoices.ts';
import type {TypeState} from './TypeState.ts';

/**
 * One type object: what it was when loaded, what it is now, and the
 * choices its save needs. Replaced whole by each edit, never changed, so an
 * edit keeps the one it replaced to put back.
 */
export interface TypeRecord {
    readonly id: TypeId;
    /** Whether the code declares it, unchangeable, or the editor made it. */
    readonly source: 'native' | 'editor';
    /** As stored; null for one this draft created. */
    readonly was: TypeState | null;
    /** As it is; null for one this draft deleted. */
    readonly now: TypeState | null;
    readonly choices: TypeChoices;
}
