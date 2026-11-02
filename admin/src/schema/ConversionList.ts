import type {ParameterDescription} from './ParameterDescription.ts';
import type {SignatureData} from './SignatureData.ts';

/** One converter or initializer, as coconut-cms:schema.conversions describes it. */
export interface ConversionDescription {
    /** The identifier a save names it by. */
    readonly kind: string;
    /** The fields a converter takes; null for an initializer. */
    readonly from: SignatureData | null;
    /** The fields it gives, or fills; null for a level initializer. */
    readonly to: SignatureData | null;
    readonly parameters: readonly ParameterDescription[];
}

/** What coconut-cms:schema.conversions gives, each list in the order registered. */
export interface ConversionList {
    readonly converters: readonly ConversionDescription[];
    readonly initializers: readonly ConversionDescription[];
    readonly levelInitializers: readonly ConversionDescription[];
}
