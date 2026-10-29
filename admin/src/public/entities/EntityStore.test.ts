import {describe, expect, it} from 'vitest';
import {ApiClient} from '../api/ApiClient.ts';
import {TypeCatalog} from '../types/TypeCatalog.ts';
import type {FieldDescription, TypeDescription, ValueDescription} from '../types/TypeDescription.ts';
import type {EntityData} from './EntityData.ts';
import type {EntityChange} from './EntityChange.ts';
import {EntityStore} from './EntityStore.ts';
import type {SessionChange} from './SessionChange.ts';
import type {UnsavedAnswer, UnsavedQuestion} from './UnsavedQuestion.ts';

/** A value description with `changes` over a non-null string scalar. */
function value(changes: Partial<ValueDescription>): ValueDescription {
    return {kind: 'scalar', nullable: false, scalarType: 'string', enumValues: null, target: null, item: null, ...changes};
}

/** A readable, writable field holding `held`. */
function field(held: ValueDescription): FieldDescription {
    return {read: true, write: true, value: held};
}

const wheel = value({kind: 'embed', target: 'shop:wheel'});
const car: TypeDescription = {
    identifier: 'shop:car',
    parent: null,
    editorExtensible: false,
    may: {read: true, create: true, update: true, delete: true},
    fields: {name: field(value({})), power: field(value({scalarType: 'int'})), wheels: field(value({kind: 'collection', item: wheel}))},
    defaults: {name: '', power: 90, wheels: []},
};
const wheelType: TypeDescription = {...car, identifier: 'shop:wheel', fields: {size: field(value({scalarType: 'int'}))}, defaults: {size: 16}};
const catalog = TypeCatalog.of([car, wheelType]);

/**
 * The backend, in memory: entities.get, query, create and update over
 * entities by id, root paths only. Counts the calls, and fails the next
 * update when told.
 */
class FakeBackend {
    public readonly entities = new Map<string, EntityData>();
    public readonly calls: {operation: string; input: Record<string, unknown>}[] = [];
    public failNextUpdate: {code: string; errors: Record<string, string[]>} | null = null;
    private nextId = 1;

    /** Puts a car named `name` in, and gives its id. */
    public addCar(name: string): string {
        const id = `c${this.nextId++}`;
        this.entities.set(id, {type: 'shop:car', id, values: {name, power: 100, wheels: [{type: 'shop:wheel', id: null, values: {size: 17}}]}});

        return id;
    }

    /** How many times `operation` was called. */
    public count(operation: string): number {
        return this.calls.filter((call) => call.operation === operation).length;
    }

    /** A client calling this backend. */
    public client(): ApiClient {
        return new ApiClient('/api', 'token', async (url, init) => {
            const operation = url.slice('/api/'.length);
            const input = JSON.parse(String(init.body)) as Record<string, unknown>;
            this.calls.push({operation, input});
            try {
                return Response.json({result: this.answer(operation, input)});
            } catch (failure) {
                return Response.json({error: failure}, {status: 422});
            }
        });
    }

    private answer(operation: string, input: Record<string, unknown>): unknown {
        switch (operation) {
            case 'coconut-cms:entities.get':
                return this.entities.get(String(input['id'])) ?? (() => {
                    throw {code: 'not_found', message: 'No such entity.', details: {}};
                })();
            case 'coconut-cms:entities.query':
                return {items: [...this.entities.values()], nextCursor: null};
            case 'coconut-cms:entities.create': {
                const id = `c${this.nextId++}`;
                const entity = {type: String(input['type']), id, values: {...car.defaults, ...(input['changes'] as Record<string, unknown>)}};
                this.entities.set(id, entity);

                return entity;
            }
            case 'coconut-cms:entities.update': {
                if (this.failNextUpdate !== null) {
                    const {code, errors} = this.failNextUpdate;
                    this.failNextUpdate = null;
                    throw {code, message: 'Refused.', details: {errors}};
                }
                const before = this.entities.get(String(input['id']))!;
                const entity = {...before, values: {...before.values, ...(input['changes'] as Record<string, unknown>)}};
                this.entities.set(entity.id, entity);

                return entity;
            }
            default:
                throw {code: 'not_found', message: `No operation ${operation}.`, details: {}};
        }
    }
}

/** An UnsavedQuestion answering `answer`, counting how often it was asked. */
class FixedAnswer implements UnsavedQuestion {
    public asked = 0;

    public constructor(public answer: UnsavedAnswer) {
    }

    public ask(): Promise<UnsavedAnswer> {
        this.asked++;

        return Promise.resolve(this.answer);
    }
}

/** A store over `backend`, answering the unsaved question with `question`. */
function storeOf(backend: FakeBackend, question: UnsavedQuestion = new FixedAnswer('discard')): EntityStore {
    return new EntityStore(backend.client(), () => Promise.resolve(catalog), question);
}

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
