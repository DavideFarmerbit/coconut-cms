import type {Conversion} from './Conversion.ts';

/** What a type's save needs that isn't its state: asked only when it applies. */
export interface TypeChoices {
    /** Reparented: what fills the levels its entries gain; null for their defaults. */
    readonly levelInitializer: Conversion | null;
}

/** A type's choices before any is made. */
export const NO_TYPE_CHOICES: TypeChoices = {levelInitializer: null};
