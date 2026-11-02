import {describe, expect, it} from 'vitest';
import type {ValueDescription} from '../../src/public/types/TypeDescription.ts';
import type {ConversionList} from '../../src/schema/ConversionList.ts';
import {SchemaListings} from '../../src/schema/SchemaListings.ts';
import type {ValidatorList} from '../../src/schema/ValidatorList.ts';
import {FakeBackend, value} from '../FakeBackend.ts';
import conversionsJson from './fixtures/conversions.json';
import validatorsJson from './fixtures/validators.json';

/** What the server gave for the core module's own: schema.conversions and schema.validators, as printed by the command line. */
const conversions = conversionsJson as unknown as ConversionList;
const validators = validatorsJson as unknown as ValidatorList;

const listings = SchemaListings.of(conversions, validators);
const kinds = (listed: readonly {kind: string}[]): string[] => listed.map((each) => each.kind);
const rules = (listed: readonly {rule: string}[]): string[] => listed.map((each) => each.rule);

const string: ValueDescription = value({});
const int: ValueDescription = value({scalarType: 'int'});
const decimal: ValueDescription = value({scalarType: 'decimal', nullable: true});
const tags: ValueDescription = value({kind: 'collection', scalarType: null, item: value({})});
const motors: ValueDescription = value({kind: 'collection', scalarType: null, item: value({kind: 'owning_reference', scalarType: null, target: 'dy:motor'})});

/** What fits a field, from the server's own listings, matched in the admin. */
describe('SchemaListings', () => {
    it('offers the converters taking what a field holds and giving what it will', () => {
        expect(kinds(listings.convertersFor(string, int))).toEqual(['coconut-cms:text-to-number', 'coconut-cms:map-values']);
        expect(kinds(listings.convertersFor(int, decimal))).toEqual(['coconut-cms:number-to-number', 'coconut-cms:map-values']);
        expect(kinds(listings.convertersFor(string, tags))).toEqual(['coconut-cms:to-single-item']);
        expect(kinds(listings.convertersFor(tags, string))).toEqual(['coconut-cms:first-item']);
        expect(kinds(listings.convertersFor(motors, string)), 'Owned items are no leaf: first-item takes none.').toEqual([]);
    });

    it('offers the initializers filling a field, and every level initializer', () => {
        expect(kinds(listings.initializersFor(motors))).toEqual(['coconut-cms:constant', 'coconut-cms:copy']);
        expect(listings.levelInitializers()).toEqual([]);
    });

    it('offers the rules checking a field as a whole', () => {
        expect(rules(listings.fieldRulesFor(string))).toEqual(['coconut-cms:max-length', 'coconut-cms:pattern']);
        expect(rules(listings.fieldRulesFor(decimal))).toEqual(['coconut-cms:range']);
        expect(rules(listings.fieldRulesFor(tags))).toEqual(['coconut-cms:max-items', 'coconut-cms:min-items', 'coconut-cms:on-items']);
        expect(rules(listings.fieldRulesFor(tags.item!)), 'What on-items takes, for those items.').toEqual(['coconut-cms:max-length', 'coconut-cms:pattern']);
    });

    it('finds one by its identifier, with the parameters a form asks for', () => {
        expect(listings.conversion('coconut-cms:map-values')?.parameters.map((each) => [each.name, each.type])).toEqual([['mapping', 'map'], ['onFailure', 'enum']]);
        expect(listings.rule('coconut-cms:on-items')?.parameters.map((each) => [each.name, each.type])).toEqual([['rules', 'field_rules']]);
        expect(listings.conversion('shop:nothing')).toBeUndefined();
    });

    it('loads both listings from the server, at once', async () => {
        const backend = new FakeBackend();
        backend.conversions = conversions;
        backend.validators = validators;

        const loaded = await SchemaListings.load(backend.client());

        expect(kinds(loaded.convertersFor(string, int))).toEqual(['coconut-cms:text-to-number', 'coconut-cms:map-values']);
        expect([backend.count('coconut-cms:schema.conversions'), backend.count('coconut-cms:schema.validators')]).toEqual([1, 1]);
    });
});
