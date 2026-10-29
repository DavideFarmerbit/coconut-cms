import {describe, expect, it} from 'vitest';
import type {ValueDescription} from '../public/types/TypeDescription.ts';
import {IconParts} from './IconParts.ts';
import {ValueWords} from './ValueWords.ts';

/** A value description, with `changes` over a non-null int scalar. */
function value(changes: Partial<ValueDescription>): ValueDescription {
    return {kind: 'scalar', nullable: false, scalarType: 'int', enumValues: null, target: null, item: null, ...changes};
}

describe('IconParts', () => {
    it('picks each layer on its own', () => {
        expect(IconParts.of(value({}))).toMatchObject({frame: 'single', glyph: 'value', color: 'int', nullable: false});
        expect(IconParts.of(value({scalarType: 'decimal', nullable: true}))).toMatchObject({color: 'float', nullable: true});
        expect(IconParts.of(value({kind: 'owning_reference', target: 'shop:turbo', nullable: true}))).toMatchObject({glyph: 'owned', color: 'entity', nullable: true});
        expect(IconParts.of(value({kind: 'value_object'}))).toMatchObject({glyph: 'value', color: 'value-object'});
    });

    it('draws a Collection as a grid of its items\' glyph and color', () => {
        const references = value({kind: 'collection', item: value({kind: 'reference', target: 'shop:maker', nullable: true})});

        expect(IconParts.of(references)).toMatchObject({frame: 'collection', glyph: 'reference', color: 'entity', nullable: true});
    });
});

describe('ValueWords', () => {
    it('names a type the way a row shows it', () => {
        expect(ValueWords.nameOf(value({scalarType: 'date_time'}))).toBe('date and time');
        expect(ValueWords.nameOf(value({kind: 'embed', target: 'shop:chassis'}))).toBe('shop:chassis');
        expect(ValueWords.nameOf(value({kind: 'collection', item: value({scalarType: 'string'})}))).toBe('string');
    });

    it('links to the entity type a field holds, and to nothing for a value', () => {
        expect(ValueWords.targetOf(value({kind: 'collection', item: value({kind: 'embed', target: 'shop:wheel'})}))).toBe('shop:wheel');
        expect(ValueWords.targetOf(value({}))).toBeNull();
    });

    it('says everything in a tooltip', () => {
        expect(ValueWords.describe(value({kind: 'owning_reference', target: 'shop:turbo', nullable: true})))
            .toBe('An owned shop:turbo or subtypes, saved and deleted with the entity; may be empty.');
        expect(ValueWords.describe(value({kind: 'collection', item: value({scalarType: 'string'})}))).toBe('A list of string values.');
    });
});
