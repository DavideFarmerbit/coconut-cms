import type {ParameterDescription} from './ParameterDescription.ts';
import type {SignatureData} from './SignatureData.ts';

/** One validator rule, as coconut-cms:schema.validators describes it. */
export interface RuleDescription {
    /** The identifier a save names it by. */
    readonly rule: string;
    /** The fields a field rule checks; null for a prototype rule. */
    readonly fits: SignatureData | null;
    readonly parameters: readonly ParameterDescription[];
}

/** What coconut-cms:schema.validators gives, each list in the order registered. */
export interface ValidatorList {
    readonly fieldRules: readonly RuleDescription[];
    readonly prototypeRules: readonly RuleDescription[];
}
