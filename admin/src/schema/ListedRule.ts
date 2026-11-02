import type {FieldSignature} from './FieldSignature.ts';
import type {ParameterDescription} from './ParameterDescription.ts';

/** One validator rule the server offers, its signature ready to match. */
export interface ListedRule {
    /** The identifier a save names it by. */
    readonly rule: string;
    /** The fields a field rule checks; null for a prototype rule. */
    readonly fits: FieldSignature | null;
    readonly parameters: readonly ParameterDescription[];
}
