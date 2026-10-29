import {describe, expect, it} from 'vitest';
import {FakeBackend, storeOf} from '../../testing/FakeBackend.ts';
import {EntityList} from './EntityList.ts';

/** Settles once `list` stops reading. */
async function settled(list: EntityList): Promise<void> {
    for (let attempt = 0; attempt < 50 && list.state === 'loading'; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

/** The names `list` shows. */
function names(list: EntityList): unknown[] {
    return list.items.map((entity) => entity.values['name']);
}

describe('EntityList', () => {
    it('turns pages through the store', async () => {
        const backend = new FakeBackend();
        ['A', 'B', 'C'].forEach((name) => backend.addCar(name));
        const list = new EntityList(storeOf(backend), {type: 'shop:car', limit: 2});
        await settled(list);

        expect(names(list)).toEqual(['A', 'B']);
        expect(list.hasPrevious).toBe(false);
        expect(list.hasNext).toBe(true);

        list.next();
        await settled(list);
        expect(names(list)).toEqual(['C']);
        expect(list.hasPrevious).toBe(true);
        expect(list.hasNext).toBe(false);

        list.previous();
        await settled(list);
        expect(names(list)).toEqual(['A', 'B']);
    });

    it('reads its page again when an entity it shows is saved', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('A');
        const store = storeOf(backend);
        const list = new EntityList(store, {type: 'shop:car'});
        await settled(list);

        const hold = store.edit(id);
        await hold.session.ready;
        hold.session.set('name', 'Renamed');
        await hold.session.save();
        await settled(list);

        expect(names(list)).toEqual(['Renamed']);
    });

    it('reads its page again for an entity of its type created, not of another type', async () => {
        const backend = new FakeBackend();
        backend.addCar('A');
        const store = storeOf(backend);
        const list = new EntityList(store, {type: 'shop:car'});
        await settled(list);
        const queries = backend.count('coconut-cms:entities.query');

        await store.add('shop:bike', {name: 'Bike'});
        await settled(list);
        expect(backend.count('coconut-cms:entities.query')).toBe(queries);

        await store.add('shop:car', {name: 'B'});
        await settled(list);
        expect(names(list)).toEqual(['A', 'B']);
    });

    it('reads its page again when an entity it shows is deleted', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('A');
        backend.addCar('B');
        const store = storeOf(backend);
        const list = new EntityList(store, {type: 'shop:car'});
        await settled(list);

        await store.delete(id);
        await settled(list);
        expect(names(list)).toEqual(['B']);
    });

    it('stops following the store once disposed', async () => {
        const backend = new FakeBackend();
        backend.addCar('A');
        const store = storeOf(backend);
        const list = new EntityList(store, {type: 'shop:car'});
        await settled(list);
        list.dispose();
        const queries = backend.count('coconut-cms:entities.query');

        await store.add('shop:car', {name: 'B'});
        expect(backend.count('coconut-cms:entities.query')).toBe(queries);
    });
});
