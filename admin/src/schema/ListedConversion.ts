import type {FieldSignature} from './FieldSignature.ts';
import type {ParameterDescription} from './ParameterDescription.ts';

/** One converter or initializer the server offers, its signatures ready to match. */
export interface ListedConversion {
    /** The identifier a save names it by. */
    readonly kind: string;
    /** The fields a converter takes; null for an initializer. */
    readonly from: FieldSignature | null;
    /** The fields it gives, or fills; null for a level initializer. */
    readonly to: FieldSignature | null;
    readonly parameters: readonly ParameterDescription[];
}
