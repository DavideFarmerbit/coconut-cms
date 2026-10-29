import {describe, expect, it} from 'vitest';
import {EntityLabels} from '../public/properties/EntityLabels.ts';
import {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {FieldDescription, TypeDescription, ValueDescription} from '../public/types/TypeDescription.ts';
import {DraftCollectionItems} from './DraftCollectionItems.ts';
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

    it('is clean again once a value is changed back', () => {
        const draft = new EntityDraft(car());
        const name = draft.slot(draft.values, 'name', 'name', value({}));
        name.set('Beta');
        name.set('Alpha');

        expect(draft.isDirty).toBe(false);
        expect(draft.changes(catalog)).toEqual({});
    });

    it('tells new, moved and changed items apart, and counts the removed ones', () => {
        const draft = new EntityDraft({...car(), wheels: [17, 18, 19].map((size) => ({type: 'shop:wheel', id: null, values: {size}}))});
        const collection = value({kind: 'collection', item: wheel});
        const items = new DraftCollectionItems(draft, draft.slot(draft.values, 'wheels', 'wheels', collection), 'wheels', collection, () => undefined);

        items.move(0, 2);
        expect([0, 1, 2].map((index) => items.statusOf(index))).toEqual([
            {isNew: false, movedFrom: 1, isChanged: false},
            {isNew: false, movedFrom: 2, isChanged: false},
            {isNew: false, movedFrom: 0, isChanged: false},
        ]);
        expect(draft.statusAt('wheels').isChanged).toBe(true);

        items.remove(0);
        items.insert({type: 'shop:wheel', id: null, values: {size: 20}}, 0);
        const wheels = items.values as {values: Record<string, unknown>}[];
        draft.slot(wheels[1]!.values, 'size', 'wheels.1.size', value({scalarType: 'int'})).set(21);

        expect(items.removedCount).toBe(1);
        expect(items.statusOf(0)).toEqual({isNew: true, movedFrom: null, isChanged: true});
        expect(items.statusOf(1)).toEqual({isNew: false, movedFrom: 2, isChanged: true});
        expect(draft.statusAt('wheels.1.size')).toEqual({isNew: false, movedFrom: null, isChanged: true});
        expect(draft.statusAt('wheels.2.size')).toEqual({isNew: false, movedFrom: null, isChanged: false});
    });

    it('is clean again once items are moved back', () => {
        const draft = new EntityDraft({...car(), wheels: [17, 18].map((size) => ({type: 'shop:wheel', id: null, values: {size}}))});
        const collection = value({kind: 'collection', item: wheel});
        const items = new DraftCollectionItems(draft, draft.slot(draft.values, 'wheels', 'wheels', collection), 'wheels', collection, () => undefined);

        items.move(0, 1);
        expect(draft.isDirty).toBe(true);
        items.move(1, 0);
        expect(draft.isDirty).toBe(false);
        expect(items.statusOf(1).movedFrom).toBeNull();
    });

    it('resets a moved item to what it was saved as, not to what was at its index', () => {
        const draft = new EntityDraft({...car(), wheels: [17, 18].map((size) => ({type: 'shop:wheel', id: null, values: {size}}))});
        const collection = value({kind: 'collection', item: wheel});
        const items = new DraftCollectionItems(draft, draft.slot(draft.values, 'wheels', 'wheels', collection), 'wheels', collection, () => undefined);
        items.move(0, 1);
        const wheels = items.values as {values: Record<string, unknown>}[];
        draft.slot(wheels[1]!.values, 'size', 'wheels.1.size', value({scalarType: 'int'})).set(30);

        draft.resetAt('wheels.1');
        expect((items.values[1] as {values: Record<string, unknown>}).values['size']).toBe(17);
        expect(items.statusOf(1).isChanged).toBe(false);
    });

    it('duplicates an owned item as a new one, every owned object in it without an id', () => {
        const draft = new EntityDraft({...car(), engines: [{type: 'shop:engine', id: 'e2', values: {spare: {type: 'shop:engine', id: 'e3', values: {}}}}]});
        const collection = value({kind: 'collection', item: engine});
        const items = new DraftCollectionItems(draft, draft.slot(draft.values, 'engines', 'engines', collection), 'engines', collection, () => undefined);
        items.duplicate(0);

        expect(items.values[1]).toEqual({type: 'shop:engine', id: null, values: {spare: {type: 'shop:engine', id: null, values: {}}}});
        expect(items.statusOf(1).isNew).toBe(true);
    });
});

describe('EntityLabels', () => {
    it('takes name, title or label, else the first string field, else the id', () => {
        expect(EntityLabels.of(catalog, 'shop:car', 'c1', {name: 'Alpha'})).toBe('Alpha');
        expect(EntityLabels.fieldOf(catalog, 'shop:maker')).toBe('title');
        expect(EntityLabels.of(catalog, 'shop:wheel', 'w1', {size: 17})).toBe('w1');
    });
});
