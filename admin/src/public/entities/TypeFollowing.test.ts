import {describe, expect, it} from 'vitest';
import {bikeType, carType, FakeBackend, FixedAnswer, field, storeOf, value, wheelType} from '../../testing/FakeBackend.ts';
import type {TypeDescription} from '../types/TypeDescription.ts';
import {EntityList} from './EntityList.ts';
import type {SessionChange} from './SessionChange.ts';

/** The car, with a new String field `colour`, "red" by default: a migration. */
const carWithColour: TypeDescription = {...carType, fields: {...carType.fields, colour: field(value({}))}, defaults: {...carType.defaults, colour: 'red'}};

/** Lets every pending read and reload settle. */
async function settle(): Promise<void> {
    for (let turn = 0; turn < 20; turn++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

/** Migrates `backend`'s cars: the car type gains a colour, every car holding one. */
function migrateCars(backend: FakeBackend): void {
    backend.types = [carWithColour, wheelType, bikeType];
    for (const [id, entity] of backend.entities) {
        if (entity.type === 'shop:car') {
            backend.entities.set(id, {...entity, values: {...entity.values, colour: 'blue'}});
        }
    }
}

describe('The entity store following the types', () => {
    it('reads a clean session of an affected type again, with the new types', async () => {
        const backend = new FakeBackend();
        const store = storeOf(backend);
        const hold = store.edit(backend.addCar('Alpha'));
        await hold.session.ready;
        const told: SessionChange[] = [];
        hold.session.onChange((change) => told.push(change));

        migrateCars(backend);
        await store.types.reload();
        await settle();

        expect(hold.session.state).toBe('ready');
        expect(hold.session.valueAt('colour')).toBe('blue');
        expect(hold.session.describe('colour')?.field).toBe('colour');
        expect(hold.session.types).toBe(store.types.catalog);
        expect(told.filter((change) => change.kind === 'reloaded')).toHaveLength(2);
    });

    it('forgets what it read of an affected type, not of others', async () => {
        const backend = new FakeBackend();
        const car = backend.addCar('Alpha');
        const bike = backend.add('shop:bike', {name: 'Bike'});
        const store = storeOf(backend);
        await Promise.all([store.get(car), store.get(bike)]);

        migrateCars(backend);
        await store.types.reload();
        await store.get(car);
        await store.get(bike);

        expect(backend.calls.filter((call) => call.operation === 'coconut-cms:entities.get').map((call) => call.input['id'])).toEqual([car, bike, car]);
    });

    it('leaves a session with edits as it is', async () => {
        const backend = new FakeBackend();
        const store = storeOf(backend);
        const hold = store.edit(backend.addCar('Alpha'));
        await hold.session.ready;
        hold.session.set('name', 'Beta');

        migrateCars(backend);
        await store.types.reload();
        await settle();

        expect(hold.session.valueAt('name')).toBe('Beta');
        expect(hold.session.describe('colour')).toBeUndefined();
    });

    it('starts a new entity without edits again from the new defaults', async () => {
        const backend = new FakeBackend();
        const store = storeOf(backend);
        const hold = store.create('shop:car');
        await hold.session.ready;

        migrateCars(backend);
        await store.types.reload();
        await settle();

        expect(hold.session.valueAt('colour')).toBe('red');
    });

    it('reads a list of an affected type again', async () => {
        const backend = new FakeBackend();
        backend.addCar('Alpha');
        const store = storeOf(backend);
        const list = new EntityList(store, {type: 'shop:car'});
        await settle();

        migrateCars(backend);
        await store.types.reload();
        await settle();

        expect(list.items[0]?.values['colour']).toBe('blue');
    });
});

describe('EntityStore.settle', () => {
    /** A store over a backend with a car being edited and a bike being edited. */
    async function editing(answer: FixedAnswer): Promise<{store: ReturnType<typeof storeOf>; backend: FakeBackend; car: ReturnType<ReturnType<typeof storeOf>['edit']>; bike: ReturnType<ReturnType<typeof storeOf>['edit']>}> {
        const backend = new FakeBackend();
        const store = storeOf(backend, answer);
        const car = store.edit(backend.addCar('Alpha'));
        const bike = store.edit(backend.add('shop:bike', {name: 'Bike'}));
        await Promise.all([car.session.ready, bike.session.ready]);
        car.session.set('name', 'Beta');
        bike.session.set('name', 'Trike');

        return {store, backend, car, bike};
    }

    it('asks only about sessions with edits whose type the change affects', async () => {
        const answer = new FixedAnswer('discard');
        const {store, car, bike} = await editing(answer);

        expect(await store.settle(['shop:wheel'])).toBe(true);
        expect(answer.asked).toBe(1);
        expect(car.session.hasEdits).toBe(false);
        expect(bike.session.hasEdits).toBe(true);
    });

    it('saves when told to', async () => {
        const answer = new FixedAnswer('save');
        const {store, backend, car} = await editing(answer);

        expect(await store.settle(['shop:car'])).toBe(true);
        expect(backend.entities.get(car.session.id!)?.values['name']).toBe('Beta');
    });

    it('answers no once one is cancelled, or fails to save', async () => {
        const cancel = new FixedAnswer('cancel');
        const cancelled = await editing(cancel);
        expect(await cancelled.store.settle(['shop:car'])).toBe(false);
        expect(cancelled.car.session.hasEdits).toBe(true);

        const save = new FixedAnswer('save');
        const failing = await editing(save);
        failing.backend.failNextUpdate = {code: 'validation_failed', errors: {name: ['Taken.']}};
        expect(await failing.store.settle(['shop:car'])).toBe(false);
    });
});
