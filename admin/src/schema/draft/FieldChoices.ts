import type {Conversion} from './Conversion.ts';

/**
 * What a field's save needs that isn't its state: how the values stored
 * reach what it holds now. Asked only when it applies; one that stops
 * fitting stays, shown as a problem, until it's chosen again.
 */
export interface FieldChoices {
    /** Retyped: the converter turning each stored value into the new one, or "reset" to drop them all; null until chosen. */
    readonly retype: Conversion | 'reset' | null;
    /** Gained by existing entries, added or moved up: what each gets instead of the default; null for the default. */
    readonly initializer: Conversion | null;
}

/** A field's choices before any is made. */
export const NO_FIELD_CHOICES: FieldChoices = {retype: null, initializer: null};
