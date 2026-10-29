import {describe, expect, it} from 'vitest';
import {FakeBackend, FixedAnswer, storeOf, value} from '../../testing/FakeBackend.ts';
import type {EntityChange} from './EntityChange.ts';
import type {EntityStore} from './EntityStore.ts';
import type {SessionChange} from './SessionChange.ts';

describe('EntityStore', () => {
    it('shares one session per entity among its holds, reading it once', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('Alpha');
        const store = storeOf(backend);

        const first = store.edit(id);
        const second = store.edit(id);
        await first.session.ready;

        expect(second.session).toBe(first.session);
        expect(first.session.state).toBe('ready');
        expect(first.session.valueAt('name')).toBe('Alpha');
        expect(backend.count('coconut-cms:entities.get')).toBe(1);
    });

    it('fails a session whose entity can\'t be read', async () => {
        const store = storeOf(new FakeBackend());
        const hold = store.edit('missing');
        await hold.session.ready;

        expect(hold.session.state).toBe('failed');
        expect(hold.session.failure).toBe('No such entity.');
    });

    it('lets a hold go at once while another stays, or while nothing is unsaved', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('Alpha');
        const question = new FixedAnswer('cancel');
        const store = storeOf(backend, question);
        const first = store.edit(id);
        const second = store.edit(id);
        await first.session.ready;
        first.session.set('name', 'Beta');

        expect(await first.release()).toBe(true);
        expect(question.asked).toBe(0);
        expect(store.edit(id).session).toBe(second.session);
    });

    it('asks when the last hold on unsaved changes goes: cancel keeps it, discard frees it', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('Alpha');
        const question = new FixedAnswer('cancel');
        const store = storeOf(backend, question);
        const hold = store.edit(id);
        await hold.session.ready;
        hold.session.set('name', 'Beta');

        expect(store.hasUnsaved).toBe(true);
        expect(await hold.release()).toBe(false);
        expect(hold.isReleased).toBe(false);
        const again = store.edit(id);
        expect(again.session).toBe(hold.session);

        question.answer = 'discard';
        await again.release();
        expect(question.asked).toBe(1);
        expect(await hold.release()).toBe(true);
        expect(question.asked).toBe(2);
        expect(store.hasUnsaved).toBe(false);

        const fresh = store.edit(id);
        await fresh.session.ready;
        expect(fresh.session).not.toBe(hold.session);
        expect(fresh.session.valueAt('name')).toBe('Alpha');
    });

    it('asks once when the last two holds on unsaved changes go at the same time', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('Alpha');
        const question = new FixedAnswer('discard');
        const store = storeOf(backend, question);
        const first = store.edit(id);
        const second = store.edit(id);
        await first.session.ready;
        first.session.set('name', 'Beta');

        expect(await Promise.all([first.release(), second.release()])).toEqual([true, true]);
        expect(question.asked).toBe(1);
        expect(store.hasUnsaved).toBe(false);
    });

    it('saves when the answer is save, and keeps the hold when that save fails', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('Alpha');
        const store = storeOf(backend, new FixedAnswer('save'));
        const hold = store.edit(id);
        await hold.session.ready;
        hold.session.set('name', 'Beta');
        backend.failNextUpdate = {code: 'validation_failed', errors: {name: ['Taken.']}};

        expect(await hold.release()).toBe(false);
        expect(hold.session.errorAt('name')).toBe('Taken.');

        expect(await hold.release()).toBe(true);
        expect(backend.entities.get(id)?.values['name']).toBe('Beta');
    });

    it('creates a new entity on its first save, then shares it by its id', async () => {
        const backend = new FakeBackend();
        const store = storeOf(backend);
        const created: EntityChange[] = [];
        store.onChange((change) => created.push(change));
        const hold = store.create('shop:car');
        await hold.session.ready;

        expect(hold.session.id).toBeNull();
        expect(hold.session.isDirty).toBe(true);
        expect(hold.session.valueAt('power')).toBe(90);
        expect(hold.session.label).toBe('New shop:car');

        hold.session.set('name', 'Gamma');
        expect(await hold.session.save()).toBe(true);

        const id = hold.session.id;
        expect(id).not.toBeNull();
        expect(backend.calls.find((call) => call.operation === 'coconut-cms:entities.create')?.input).toEqual({type: 'shop:car', changes: {name: 'Gamma'}});
        expect(store.edit(id!).session).toBe(hold.session);
        expect(created.map((change) => change.kind)).toEqual(['created']);
        expect(hold.session.isDirty).toBe(false);
    });

    it('tells its listeners of a save, and labels follow it', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('Alpha');
        const store = storeOf(backend);
        const changes: EntityChange[] = [];
        store.onChange((change) => changes.push(change));
        expect(await store.labelOf({type: 'shop:car', id})).toBe('Alpha');

        const hold = store.edit(id);
        await hold.session.ready;
        hold.session.set('name', 'Beta');
        await hold.session.save();

        expect(changes).toEqual([{kind: 'updated', entity: {type: 'shop:car', id, values: {name: 'Beta', power: 100, wheels: [{type: 'shop:wheel', id: null, values: {size: 17}}]}}}]);
        expect(await store.labelOf({type: 'shop:car', id})).toBe('Beta');
        expect(backend.count('coconut-cms:entities.get')).toBe(1);
    });

    it('keeps what a query read', async () => {
        const backend = new FakeBackend();
        const id = backend.addCar('Alpha');
        const store = storeOf(backend);
        await store.query({type: 'shop:car'});
        await store.edit(id).session.ready;

        expect(backend.count('coconut-cms:entities.get')).toBe(0);
    });
});

describe('EntitySession', () => {
    /** A ready session of a saved car named Alpha, and the changes it told. */
    async function session(): Promise<{store: EntityStore; backend: FakeBackend; told: SessionChange[]; edit: ReturnType<EntityStore['edit']>}> {
        const backend = new FakeBackend();
        const store = storeOf(backend);
        const edit = store.edit(backend.addCar('Alpha'));
        await edit.session.ready;
        const told: SessionChange[] = [];
        edit.session.onChange((change) => told.push(change));

        return {store, backend, told, edit};
    }

    it('tells which values changed, and which view changed them', async () => {
        const {told, edit} = await session();
        const view = {};
        edit.session.set('wheels.0.size', 18, view);

        expect(told).toEqual([{kind: 'values', paths: ['wheels.0.size'], source: view}, {kind: 'state'}]);
        expect(edit.session.statusAt('wheels.0.size').isChanged).toBe(true);
        expect(edit.session.isDirty).toBe(true);
    });

    it('describes properties by path', async () => {
        const {edit} = await session();

        expect(edit.session.describe('wheels.0.size')).toEqual({path: 'wheels.0.size', field: 'size', value: value({scalarType: 'int'}), holderType: 'shop:wheel', writable: true});
        expect(edit.session.describe('wheels.0')?.field).toBeNull();
        expect(edit.session.describe('wheels.3')).toBeUndefined();
        expect(edit.session.describe('nothing')).toBeUndefined();
    });

    it('tells a Collection\'s operations as a change of shape', async () => {
        const {told, edit} = await session();
        const items = edit.session.itemsAt('wheels');
        items?.insert({type: 'shop:wheel', id: null, values: {size: 19}});

        expect(told[0]).toEqual({kind: 'shape', path: 'wheels', source: null});
        expect(edit.session.statusAt('wheels.1').isNew).toBe(true);
        expect(edit.session.itemsAt('name')).toBeUndefined();
    });

    it('resets to the default, and back to what was saved', async () => {
        const {edit} = await session();

        expect(edit.session.defaultAt('power')).toBe(90);
        expect(edit.session.defaultAt('wheels.0')).toBeUndefined();
        edit.session.resetToDefaultAt('power');
        expect(edit.session.valueAt('power')).toBe(90);

        edit.session.resetAt('power');
        expect(edit.session.valueAt('power')).toBe(100);
        expect(edit.session.isDirty).toBe(false);
    });

    it('puts a failed save\'s errors at their paths until edited, and one about the whole entity apart', async () => {
        const {backend, edit} = await session();
        edit.session.set('name', '');
        backend.failNextUpdate = {code: 'validation_failed', errors: {'changes.name': ['Required.'], '': ['Not now.']}};

        expect(await edit.session.save()).toBe(false);
        expect(edit.session.errorAt('name')).toBe('Required.');
        expect(edit.session.entityErrorMessage).toBe('Not now.');
        expect(edit.session.valueAt('name')).toBe('');

        edit.session.set('name', 'Delta');
        expect(edit.session.errorAt('name')).toBeUndefined();
    });

    it('reverts every change', async () => {
        const {told, edit} = await session();
        edit.session.set('name', 'Beta');
        edit.session.revert();

        expect(edit.session.valueAt('name')).toBe('Alpha');
        expect(edit.session.isDirty).toBe(false);
        expect(told[told.length - 1]).toEqual({kind: 'reloaded'});
    });
});
