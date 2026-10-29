import {ApiClient} from '../public/api/ApiClient.ts';
import type {EntityData} from '../public/entities/EntityData.ts';
import {EntityStore} from '../public/entities/EntityStore.ts';
import type {UnsavedAnswer, UnsavedQuestion} from '../public/entities/UnsavedQuestion.ts';
import {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {FieldDescription, TypeDescription, ValueDescription} from '../public/types/TypeDescription.ts';

/** A value description with `changes` over a non-null string scalar. */
export function value(changes: Partial<ValueDescription>): ValueDescription {
    return {kind: 'scalar', nullable: false, scalarType: 'string', enumValues: null, target: null, item: null, ...changes};
}

/** A readable, writable field holding `held`. */
export function field(held: ValueDescription): FieldDescription {
    return {read: true, write: true, value: held};
}

/** The tests' car: a name, a power, wheels. */
export const carType: TypeDescription = {
    identifier: 'shop:car',
    parent: null,
    editorExtensible: false,
    may: {read: true, create: true, update: true, delete: true},
    fields: {name: field(value({})), power: field(value({scalarType: 'int'})), wheels: field(value({kind: 'collection', item: value({kind: 'embed', target: 'shop:wheel'})}))},
    defaults: {name: '', power: 90, wheels: []},
};

/** The tests' wheel, embedded in a car. */
export const wheelType: TypeDescription = {...carType, identifier: 'shop:wheel', fields: {size: field(value({scalarType: 'int'}))}, defaults: {size: 16}};

/** The tests' bike: another type, never listed with cars. */
export const bikeType: TypeDescription = {...carType, identifier: 'shop:bike', fields: {name: field(value({}))}, defaults: {name: ''}};

/** Every type the tests know. */
export const testCatalog = TypeCatalog.of([carType, wheelType, bikeType]);

/**
 * The backend, in memory, behind a real ApiClient: entities.get, query,
 * create, update and delete over entities by id, root paths only. A query
 * lists a type's entities by id, a page at a time. Counts the calls, and
 * fails the next update when told.
 */
export class FakeBackend {
    public readonly entities = new Map<string, EntityData>();
    public readonly calls: {operation: string; input: Record<string, unknown>}[] = [];
    public failNextUpdate: {code: string; errors: Record<string, string[]>} | null = null;
    private nextId = 1;

    /** Puts a car named `name` in, and gives its id. */
    public addCar(name: string): string {
        return this.add('shop:car', {name, power: 100, wheels: [{type: 'shop:wheel', id: null, values: {size: 17}}]});
    }

    /** Puts an entity of `type` holding `values` in, and gives its id. */
    public add(type: string, values: Record<string, unknown>): string {
        const id = `c${String(this.nextId++).padStart(3, '0')}`;
        this.entities.set(id, {type, id, values});

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
                return this.entities.get(String(input['id'])) ?? FakeBackend.fail('not_found', 'No such entity.');
            case 'coconut-cms:entities.query':
                return this.query(input);
            case 'coconut-cms:entities.create': {
                const id = this.add(String(input['type']), {...carType.defaults, ...(input['changes'] as Record<string, unknown>)});

                return this.entities.get(id);
            }
            case 'coconut-cms:entities.update': {
                if (this.failNextUpdate !== null) {
                    const {code, errors} = this.failNextUpdate;
                    this.failNextUpdate = null;
                    throw {code, message: 'Refused.', details: {errors}};
                }
                const before = this.entities.get(String(input['id'])) ?? FakeBackend.fail('not_found', 'No such entity.');
                const entity = {...before, values: {...before.values, ...(input['changes'] as Record<string, unknown>)}};
                this.entities.set(entity.id, entity);

                return entity;
            }
            case 'coconut-cms:entities.delete':
                this.entities.delete(String(input['id']));

                return {id: input['id']};
            default:
                return FakeBackend.fail('not_found', `No operation ${operation}.`);
        }
    }

    /** A page of the queried type's entities, by id, after `after`. */
    private query(input: Record<string, unknown>): unknown {
        const after = typeof input['after'] === 'string' ? input['after'] : '';
        const limit = typeof input['limit'] === 'number' ? input['limit'] : 20;
        const all = [...this.entities.values()].filter((entity) => entity.type === input['type'] && entity.id > after).sort((a, b) => a.id.localeCompare(b.id));
        const items = all.slice(0, limit);

        return {items, nextCursor: all.length > limit ? items[items.length - 1]!.id : null};
    }

    private static fail(code: string, message: string): never {
        throw {code, message, details: {}};
    }
}

/** An UnsavedQuestion answering `answer`, counting how often it was asked. */
export class FixedAnswer implements UnsavedQuestion {
    public asked = 0;

    public constructor(public answer: UnsavedAnswer) {
    }

    public ask(): Promise<UnsavedAnswer> {
        this.asked++;

        return Promise.resolve(this.answer);
    }
}

/** A store over `backend`, answering the unsaved question with `question`. */
export function storeOf(backend: FakeBackend, question: UnsavedQuestion = new FixedAnswer('discard')): EntityStore {
    return new EntityStore(backend.client(), () => Promise.resolve(testCatalog), question);
}
