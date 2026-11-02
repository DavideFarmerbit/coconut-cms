import type {ApiClient} from '../public/api/ApiClient.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';
import type {ConversionDescription, ConversionList} from './ConversionList.ts';
import {FieldSignature} from './FieldSignature.ts';
import type {ListedConversion} from './ListedConversion.ts';
import type {ListedRule} from './ListedRule.ts';
import type {RuleDescription, ValidatorList} from './ValidatorList.ts';

/**
 * Every converter, initializer and validator rule the server offers, as
 * schema.conversions and schema.validators list them, loaded once: the
 * modules register them at boot, so they change only with a deploy. Asked
 * what fits a field, it matches here, with the server's rules, so the
 * editor never waits on the server for it.
 */
export class SchemaListings {
    private constructor(
        private readonly converters: readonly ListedConversion[],
        private readonly initializers: readonly ListedConversion[],
        private readonly levels: readonly ListedConversion[],
        private readonly fieldRules: readonly ListedRule[],
        private readonly prototypes: readonly ListedRule[],
    ) {
    }

    /** The listings the server gives, asked for both at once. */
    public static async load(api: ApiClient): Promise<SchemaListings> {
        const [conversions, validators] = await Promise.all([
            api.call<ConversionList>('coconut-cms:schema.conversions'),
            api.call<ValidatorList>('coconut-cms:schema.validators'),
        ]);

        return SchemaListings.of(conversions, validators);
    }

    /** The listings `conversions` and `validators` describe. */
    public static of(conversions: ConversionList, validators: ValidatorList): SchemaListings {
        return new SchemaListings(
            conversions.converters.map(SchemaListings.conversion),
            conversions.initializers.map(SchemaListings.conversion),
            conversions.levelInitializers.map(SchemaListings.conversion),
            validators.fieldRules.map(SchemaListings.rule),
            validators.prototypeRules.map(SchemaListings.rule),
        );
    }

    /** The converters for a field holding `from` that will hold `to`: those taking the one and giving the other. */
    public convertersFor(from: ValueDescription, to: ValueDescription): readonly ListedConversion[] {
        return this.converters.filter((each) => each.from?.matches(from) === true && each.to?.matches(to) === true);
    }

    /** The initializers filling a field holding `to`: for one existing entries gain. */
    public initializersFor(to: ValueDescription): readonly ListedConversion[] {
        return this.initializers.filter((each) => each.to?.matches(to) === true);
    }

    /** The level initializers: for the levels a reparent gains. Each is written for the types it fills, so all are offered. */
    public levelInitializers(): readonly ListedConversion[] {
        return this.levels;
    }

    /** The field rules checking a field holding `value`, as a whole: OnItems puts one on a Collection's items. */
    public fieldRulesFor(value: ValueDescription): readonly ListedRule[] {
        return this.fieldRules.filter((each) => each.fits?.matches(value) === true);
    }

    /** The cross-field rules: each checks its own configuration against the type's fields. */
    public prototypeRules(): readonly ListedRule[] {
        return this.prototypes;
    }

    /** The converter or initializer named `kind`, of any list; undefined when none is offered. */
    public conversion(kind: string): ListedConversion | undefined {
        return [...this.converters, ...this.initializers, ...this.levels].find((each) => each.kind === kind);
    }

    /** The field or cross-field rule named `rule`; undefined when none is offered. */
    public rule(rule: string): ListedRule | undefined {
        return [...this.fieldRules, ...this.prototypes].find((each) => each.rule === rule);
    }

    private static conversion(description: ConversionDescription): ListedConversion {
        return {
            kind: description.kind,
            from: description.from === null ? null : FieldSignature.of(description.from),
            to: description.to === null ? null : FieldSignature.of(description.to),
            parameters: description.parameters,
        };
    }

    private static rule(description: RuleDescription): ListedRule {
        return {
            rule: description.rule,
            fits: description.fits === null ? null : FieldSignature.of(description.fits),
            parameters: description.parameters,
        };
    }
}
