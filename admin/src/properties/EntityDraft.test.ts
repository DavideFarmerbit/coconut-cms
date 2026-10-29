import {describe, expect, it} from 'vitest';
import {EntityLabels} from '../public/properties/EntityLabels.ts';
import {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {FieldDescription, TypeDescription, ValueDescription} from '../public/types/TypeDescription.ts';
import {EntityDraft} from './EntityDraft.ts';

/** A value description with `changes` over a non-null string scalar. */
function value(changes: Partial<ValueDescription>): ValueDescription {
    return {kind: 'scalar', nullable: false, scalarType: 'string', enumValues: null, target: null, item: null, ...changes};
}

/** A readable, writable field holding `held`. */
function field(held: ValueDescription): FieldDescription {
    return {read: true, write: true, value: held};
}

/** A type with `fields`. */
function type(identifier: string, fields: Record<string, FieldDescription>, parent: string | null = null): TypeDescription {
    return {identifier, parent, editorExtensible: false, may: {read: true, create: true, update: true, delete: true}, fields, defaults: {}};
}

const wheel = value({kind: 'embed', target: 'shop:wheel'});
const engine = value({kind: 'owning_reference', target: 'shop:engine', nullable: true});
const catalog = TypeCatalog.of([
    type('shop:vehicle', {name: field(value({}))}),
    type('shop:car', {
        chassis: field(value({kind: 'embed', target: 'shop:chassis'})),
        engine: field(engine),
        wheels: field(value({kind: 'collection', item: wheel})),
        maker: field(value({kind: 'reference', target: 'shop:maker', nullable: true})),
    }, 'shop:vehicle'),
    type('shop:chassis', {weight: field(value({scalarType: 'int'}))}),
    type('shop:wheel', {size: field(value({scalarType: 'int'}))}),
    type('shop:engine', {horsepower: field(value({scalarType: 'int'}))}),
    type('shop:maker', {code: field(value({scalarType: 'int'})), title: field(value({}))}),
]);

/** A saved car. */
function car(): Record<string, unknown> {
    return {
        name: 'Alpha',
        chassis: {type: 'shop:chassis', id: null, values: {weight: 1000}},
        engine: {type: 'shop:engine', id: 'e1', values: {horsepower: 100}},
        wheels: [{type: 'shop:wheel', id: null, values: {size: 17}}],
        maker: {type: 'shop:maker', id: 'm1'},
    };
}

describe('EntityDraft', () => {
    it('sends each change by path, in the form an update takes', () => {
        const draft = new EntityDraft(car());
        const values = draft.values;
        draft.slot(values, 'name', 'name', value({})).set('Beta');
        const chassis = values['chassis'] as {values: Record<string, unknown>};
        draft.slot(chassis.values, 'weight', 'chassis:weight', value({scalarType: 'int'})).set(1200);
        draft.slot(values, 'maker', 'maker', value({kind: 'reference', target: 'shop:maker', nullable: true})).set({type: 'shop:maker', id: 'm2'});

        expect(draft.isDirty).toBe(true);
        expect(draft.changes(catalog)).toEqual({'name': 'Beta', 'chassis:weight': 1200, 'maker': 'm2'});
    });

    it('lets a whole Collection carry the edits made in its items', () => {
        const draft = new EntityDraft(car());
        const wheels = draft.values['wheels'] as {type: string; id: null; values: Record<string, unknown>}[];
        draft.slot(wheels[0]!.values, 'size', 'wheels.0.size', value({scalarType: 'int'})).set(18);
        draft.slot(draft.values, 'wheels', 'wheels', value({kind: 'collection', item: wheel}))
            .set([...wheels, {type: 'shop:wheel', id: null, values: {size: 19}}]);

        expect(draft.changes(catalog)).toEqual({wheels: [{values: {size: 18}}, {values: {size: 19}}]});
    });

    it('keeps an owned object\'s id, and a new one has none', () => {
        const draft = new EntityDraft(car());
        draft.slot(draft.values, 'engine', 'engine', engine).set({type: 'shop:engine', id: 'e1', values: {horsepower: 150}});
        expect(draft.changes(catalog)).toEqual({engine: {type: 'shop:engine', id: 'e1', values: {horsepower: 150}}});

        draft.slot(draft.values, 'engine', 'engine', engine).set({type: 'shop:engine', id: null, values: {horsepower: 90}});
        expect(draft.changes(catalog)).toEqual({engine: {type: 'shop:engine', values: {horsepower: 90}}});
    });

    it('resets one property, or reverts them all', () => {
        const draft = new EntityDraft(car());
        draft.slot(draft.values, 'name', 'name', value({})).set('Beta');
        const chassis = draft.values['chassis'] as {values: Record<string, unknown>};
        draft.slot(chassis.values, 'weight', 'chassis:weight', value({scalarType: 'int'})).set(1200);

        draft.resetAt('chassis');
        expect(draft.changes(catalog)).toEqual({name: 'Beta'});
        expect((draft.values['chassis'] as {values: Record<string, unknown>}).values['weight']).toBe(1000);

        draft.revert();
        expect(draft.isDirty).toBe(false);
        expect(draft.values['name']).toBe('Alpha');
    });
});

describe('EntityLabels', () => {
    it('takes name, title or label, else the first string field, else the id', () => {
        expect(EntityLabels.of(catalog, 'shop:car', 'c1', {name: 'Alpha'})).toBe('Alpha');
        expect(EntityLabels.fieldOf(catalog, 'shop:maker')).toBe('title');
        expect(EntityLabels.of(catalog, 'shop:wheel', 'w1', {size: 17})).toBe('w1');
    });
});
