/**
 * What a converter's, initializer's or validator rule's parameter takes:
 * "mixed" is a value in stored form, "enum" one of its choices,
 * "registered_enum" an enum's registered identifier, "map" a list of
 * `{from, to}` pairs, "field_rules" a list of `{rule, config}`.
 */
export type ParameterType = 'string' | 'int' | 'float' | 'bool' | 'mixed' | 'enum' | 'registered_enum' | 'map' | 'field_rules';

/** One parameter, as the server describes it: what a form asks for it. */
export interface ParameterDescription {
    readonly name: string;
    readonly type: ParameterType;
    readonly nullable: boolean;
    /** Whether it may be left out: it then takes `default`. */
    readonly optional: boolean;
    readonly default: unknown;
    /** An enum's values, in order; null for any other type. */
    readonly choices: readonly (string | number)[] | null;
}
