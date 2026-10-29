import {describe, expect, it} from 'vitest';
import type {EntityData} from '../public/entities/EntityData.ts';
import {EntitySession, type SessionSaver} from '../public/entities/EntitySession.ts';
import type {PropertyFilter} from '../public/properties/PropertyFilter.ts';
import {PropertyFilters} from '../public/properties/PropertyFilters.ts';
import type {PropertyInfo} from '../public/properties/PropertyInfo.ts';
import {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {FieldDescription, TypeDescription, ValueDescription} from '../public/types/TypeDescription.ts';
import {PropertyVisibility} from './PropertyVisibility.ts';

/** A value description with `changes` over a non-null string scalar. */
function value(changes: Partial<ValueDescription>): ValueDescription {
    return {kind: 'scalar', nullable: false, scalarType: 'string', enumValues: null, target: null, item: null, ...changes};
}

/** A readable field holding `held`, writable unless told. */
function field(held: ValueDescription, write = true, read = true): FieldDescription {
    return {read, write, value: held};
}

/** A type with `fields`. */
function type(identifier: string, fields: Record<string, FieldDescription>): TypeDescription {
    return {identifier, parent: null, editorExtensible: false, may: {read: true, create: true, update: true, delete: true}, fields, defaults: {}};
}

const catalog = TypeCatalog.of([
    type('shop:car', {
        name: field(value({}), false),
        chassis: field(value({kind: 'embed', target: 'shop:chassis'})),
        engine: field(value({kind: 'owning_reference', target: 'shop:engine', nullable: true})),
        wheels: field(value({kind: 'collection', item: value({kind: 'embed', target: 'shop:wheel'})})),
        secret: field(value({}), true, false),
    }),
    type('shop:chassis', {weight: field(value({scalarType: 'int'}))}),
    type('shop:engine', {horsepower: field(value({scalarType: 'int'})), color: field(value({kind: 'value_object', target: 'shop:color'}))}),
    type('shop:wheel', {size: field(value({scalarType: 'int'})), color: field(value({kind: 'value_object', target: 'shop:color'}))}),
]);

const car: EntityData = {
    type: 'shop:car',
    id: 'c1',
    values: {
        name: 'Alpha',
        chassis: {type: 'shop:chassis', id: null, values: {weight: 1000}},
        engine: {type: 'shop:engine', id: 'e1', values: {horsepower: 100, color: '#f00'}},
        wheels: [{type: 'shop:wheel', id: null, values: {size: 17, color: '#000'}}],
    },
};

/** Saves nothing: these sessions are only read. */
class NoSaver implements SessionSaver {
    public add(): Promise<EntityData> {
        throw new Error('Not saved here.');
    }

    public update(): Promise<EntityData> {
        throw new Error('Not saved here.');
    }

    public created(): void {
    }
}

/** How each property of the car shows under `filter`. */
async function visibilityUnder(filter: PropertyFilter | null): Promise<Record<string, string>> {
    const session = EntitySession.of(new NoSaver(), car.id, Promise.resolve(car), Promise.resolve(catalog));
    await session.ready;
    const visibility = new PropertyVisibility(session, filter);
    const shown: Record<string, string> = {};
    const walk = (path: string): void => {
        shown[path] = visibility.of(path);
        visibility.childPaths(path).forEach(walk);
    };
    visibility.rootPaths().forEach(walk);

    return shown;
}

/** A property `changes` describe, over a writable root String field "name". */
function info(changes: Partial<PropertyInfo>): PropertyInfo {
    return {path: 'name', field: 'name', value: value({}), holderType: 'shop:car', writable: true, ...changes};
}

describe('PropertyFilters', () => {
    it('accepts by writability, kind, type held, place and path', () => {
        expect(PropertyFilters.writable().accepts(info({writable: false}))).toBe(false);
        expect(PropertyFilters.ofKind('collection', 'reference').accepts(info({value: value({kind: 'reference'})}))).toBe(true);
        expect(PropertyFilters.ofKind('collection').accepts(info({}))).toBe(false);
        expect(PropertyFilters.holding('shop:color').accepts(info({value: value({kind: 'value_object', target: 'shop:color'})}))).toBe(true);
        expect(PropertyFilters.holding('int').accepts(info({value: value({scalarType: 'int'})}))).toBe(true);
        expect(PropertyFilters.under('engine').accepts(info({path: 'engine.horsepower'}))).toBe(true);
        expect(PropertyFilters.under('engine').accepts(info({path: 'engine'}))).toBe(false);
        expect(PropertyFilters.under('engine').accepts(info({path: 'engines.0'}))).toBe(false);
        expect(PropertyFilters.paths('name', 'engine').accepts(info({path: 'engine'}))).toBe(true);
    });

    it('combines filters', () => {
        const writableInt = PropertyFilters.all(PropertyFilters.writable(), PropertyFilters.holding('int'));
        expect(writableInt.accepts(info({value: value({scalarType: 'int'})}))).toBe(true);
        expect(writableInt.accepts(info({value: value({scalarType: 'int'}), writable: false}))).toBe(false);
        expect(PropertyFilters.any(PropertyFilters.paths('x'), PropertyFilters.writable()).accepts(info({}))).toBe(true);
    });
});

describe('PropertyVisibility', () => {
    it('shows every readable property in full with no filter', async () => {
        expect(await visibilityUnder(null)).toEqual({
            'name': 'full',
            'chassis': 'full',
            'chassis:weight': 'full',
            'engine': 'full',
            'engine.horsepower': 'full',
            'engine.color': 'full',
            'wheels': 'full',
            'wheels.0': 'full',
            'wheels.0.size': 'full',
            'wheels.0.color': 'full',
        });
    });

    it('keeps the ancestors of a match as plain parents, and hides the rest', async () => {
        expect(await visibilityUnder(PropertyFilters.holding('shop:color'))).toEqual({
            'name': 'hidden',
            'chassis': 'hidden',
            'chassis:weight': 'hidden',
            'engine': 'parent',
            'engine.horsepower': 'hidden',
            'engine.color': 'full',
            'wheels': 'parent',
            'wheels.0': 'parent',
            'wheels.0.size': 'hidden',
            'wheels.0.color': 'full',
        });
    });

    it('shows what\'s in an accepted property, whatever the filter says of it', async () => {
        const shown = await visibilityUnder(PropertyFilters.paths('engine'));

        expect(shown['engine']).toBe('full');
        expect(shown['engine.horsepower']).toBe('full');
        expect(shown['wheels']).toBe('hidden');
    });

    it('shows an owned object\'s fields under it, the object a plain parent', async () => {
        const shown = await visibilityUnder(PropertyFilters.under('engine'));

        expect(shown['engine']).toBe('parent');
        expect(shown['engine.horsepower']).toBe('full');
        expect(shown['name']).toBe('hidden');
    });

    it('hides the read-only with writable only', async () => {
        expect((await visibilityUnder(PropertyFilters.writable()))['name']).toBe('hidden');
    });
});
