import {describe, expect, it} from 'vitest';
import {bikeType, carType, FakeBackend, field, value, wheelType} from '../../testing/FakeBackend.ts';
import {TypeChanges} from '../../types/TypeChanges.ts';
import {TypeCatalog} from './TypeCatalog.ts';
import type {TypeChange} from './TypeChange.ts';
import type {TypeDescription} from './TypeDescription.ts';
import {TypeStore} from './TypeStore.ts';

/** `type` with its field `name` holding what `held` describes. */
function withField(type: TypeDescription, name: string, held = value({})): TypeDescription {
    return {...type, fields: {...type.fields, [name]: field(held)}};
}

/** A sports car, extending the car. */
const sportsCar: TypeDescription = {...carType, identifier: 'shop:sports_car', parent: 'shop:car', fields: {spoiler: field(value({kind: 'scalar', scalarType: 'bool'}))}};

/** A garage owning cars, and referencing a bike. */
const garage: TypeDescription = {
    ...bikeType,
    identifier: 'shop:garage',
    fields: {
        cars: field(value({kind: 'collection', item: value({kind: 'owning_reference', target: 'shop:car'})})),
        favourite: field(value({kind: 'reference', target: 'shop:bike'})),
    },
};

/** The sets of `change`, sorted, for comparing. */
function sets(change: TypeChange | null): {changed: string[]; affected: string[]} | null {
    return change === null ? null : {changed: [...change.changed].sort(), affected: [...change.affected].sort()};
}

describe('TypeChanges', () => {
    const before = TypeCatalog.of([carType, sportsCar, wheelType, bikeType, garage]);

    it('finds nothing when nothing changed', () => {
        expect(TypeChanges.between(before, TypeCatalog.of([garage, bikeType, wheelType, sportsCar, carType]))).toBeNull();
    });

    it('affects subtypes, holders and holders of holders, not referencing types', () => {
        const after = TypeCatalog.of([carType, sportsCar, withField(wheelType, 'rim'), bikeType, garage]);

        expect(sets(TypeChanges.between(before, after))).toEqual({
            changed: ['shop:wheel'],
            affected: ['shop:car', 'shop:garage', 'shop:sports_car', 'shop:wheel'],
        });
    });

    it('leaves a type only referenced alone', () => {
        const after = TypeCatalog.of([carType, sportsCar, wheelType, withField(bikeType, 'bell'), garage]);

        expect(sets(TypeChanges.between(before, after))).toEqual({changed: ['shop:bike'], affected: ['shop:bike']});
    });

    it('counts a type new or gone as changed, and its holders in the catalog before as affected', () => {
        const after = TypeCatalog.of([{...carType, fields: {name: carType.fields['name']!}}, sportsCar, bikeType, garage]);

        expect(sets(TypeChanges.between(before, after))).toEqual({
            changed: ['shop:car', 'shop:wheel'],
            affected: ['shop:car', 'shop:garage', 'shop:sports_car', 'shop:wheel'],
        });
    });
});

describe('TypeStore', () => {
    it('reads the types once ready, without telling of it', async () => {
        const store = new TypeStore(new FakeBackend().client());
        const told: TypeChange[] = [];
        store.onChange((change) => told.push(change));
        await store.ready;

        expect(store.catalog.get('shop:car')).toEqual(carType);
        expect(told).toEqual([]);
    });

    it('swaps its catalog on a reload that finds a change, and tells of it', async () => {
        const backend = new FakeBackend();
        const store = new TypeStore(backend.client());
        await store.ready;
        const before = store.catalog;
        const told: TypeChange[] = [];
        store.onChange((change) => told.push(change));

        expect(await store.reload()).toBeNull();
        expect(store.catalog).toBe(before);

        backend.types = [carType, withField(wheelType, 'rim'), bikeType];
        const change = await store.reload();

        expect(sets(change)).toEqual({changed: ['shop:wheel'], affected: ['shop:car', 'shop:wheel']});
        expect(told).toEqual([change]);
        expect(store.catalog).not.toBe(before);
        expect(before.get('shop:wheel')).toEqual(wheelType);
        expect(store.catalog.get('shop:wheel')?.fields['rim']).toBeDefined();
    });

    it('drops a reload overtaken by a later one', async () => {
        const backend = new FakeBackend();
        const store = new TypeStore(backend.client());
        await store.ready;
        const told: TypeChange[] = [];
        store.onChange((change) => told.push(change));

        backend.types = [carType, withField(wheelType, 'rim'), bikeType];
        const [first, second] = await Promise.all([store.reload(), store.reload()]);

        expect(first).toBeNull();
        expect(second).not.toBeNull();
        expect(told).toHaveLength(1);
    });
});
