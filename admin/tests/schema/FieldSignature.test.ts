import {describe, expect, it} from 'vitest';
import type {ValueDescription} from '../../src/public/types/TypeDescription.ts';
import {FieldSignature} from '../../src/schema/FieldSignature.ts';
import type {ShapeData, SignatureData} from '../../src/schema/SignatureData.ts';
import {value} from '../FakeBackend.ts';

/** A shape, every key left out matching any, as the server sends it. */
function shape(changes: Partial<ShapeData>): ShapeData {
    return {kind: null, scalarType: null, target: null, item: null, ...changes};
}

const scalar = (scalarType: string | null = null): ShapeData => shape({kind: 'scalar', scalarType});
const text: SignatureData = {oneOf: [scalar('string'), scalar('text')]};
const number: SignatureData = {oneOf: [scalar('int'), scalar('float'), scalar('decimal')]};

const name: ValueDescription = value({nullable: true});
const tags: ValueDescription = value({kind: 'collection', item: value({})});
const maker: ValueDescription = value({kind: 'reference', scalarType: null, target: 'shop:maker'});

/** Matched the server's way: the same cases as its own FieldSignatureTest. */
describe('FieldSignature', () => {
    it('matches any for what it leaves out, and never asks about nullability', () => {
        expect(FieldSignature.of(shape({})).matches(maker)).toBe(true);
        expect(FieldSignature.of(scalar()).matches(name)).toBe(true);
        expect(FieldSignature.of(scalar('string')).matches(name)).toBe(true);
        expect(FieldSignature.of(text).matches(name)).toBe(true);
        expect(FieldSignature.of(number).matches(name)).toBe(false);
        expect(FieldSignature.of({oneOf: [shape({kind: 'reference'}), scalar('string')]}).matches(name)).toBe(true);
        expect(FieldSignature.of(shape({kind: 'collection', item: {oneOf: [shape({kind: 'reference'}), text]}})).matches(tags)).toBe(true);
        expect(FieldSignature.of(shape({kind: 'collection'})).matches(tags)).toBe(true);
        expect(FieldSignature.of(shape({kind: 'collection', item: scalar('string')})).matches(tags)).toBe(true);
        expect(FieldSignature.of(shape({kind: 'collection', item: shape({kind: 'reference'})})).matches(tags)).toBe(false);
        expect(FieldSignature.of(shape({kind: 'reference'})).matches(maker)).toBe(true);
        expect(FieldSignature.of(shape({kind: 'reference', target: 'shop:yard'})).matches(maker)).toBe(false);
        expect(FieldSignature.of(shape({kind: 'owning_reference'})).matches(maker)).toBe(false);
    });

    it('matches a value object by its registered identifier', () => {
        const money = value({kind: 'value_object', scalarType: null, target: 'shop:money'});

        expect(FieldSignature.of(shape({kind: 'value_object', target: 'shop:money'})).matches(money)).toBe(true);
        expect(FieldSignature.of(shape({kind: 'value_object', target: 'shop:weight'})).matches(money)).toBe(false);
    });

    it('describes itself as the server does', () => {
        expect(FieldSignature.of(shape({})).describe()).toBe('any field');
        expect(FieldSignature.of(number).describe()).toBe('a Scalar of int, float or decimal');
        expect(FieldSignature.of({oneOf: [shape({kind: 'embed', target: 'shop:engine'}), shape({kind: 'owning_reference', target: 'shop:engine'})]}).describe())
            .toBe('an Embed of shop:engine or an owned object of shop:engine');
        expect(FieldSignature.of(shape({kind: 'collection', item: shape({kind: 'reference', target: 'shop:maker'})})).describe()).toBe('a Collection of a Reference of shop:maker');
        expect(FieldSignature.of(shape({kind: 'owning_reference', target: 'shop:motor'})).describe()).toBe('an owned object of shop:motor');
        expect(FieldSignature.of(shape({kind: 'collection'})).describe()).toBe('a Collection of any items');
        expect(FieldSignature.of(shape({kind: 'collection', item: text})).describe()).toBe('a Collection of a Scalar of string or text');
    });
});
