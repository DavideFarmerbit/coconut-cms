import {describe, expect, it} from 'vitest';
import {carType, FakeBackend} from '../../FakeBackend.ts';
import {FakeContext} from '../../FakeContext.ts';
import {Editors} from '../../../src/public/editors/Editors.ts';
import {TypeStore} from '../../../src/public/types/TypeStore.ts';
import {tabTypeId} from '../../../src/public/workspace/Ids.ts';

const CAR_TAB = tabTypeId('test:car');
const SPORTS_TAB = tabTypeId('test:sports');

/** A context whose types are the car, a sports car extending it, and a bike. */
async function context(): Promise<FakeContext> {
    const backend = new FakeBackend();
    backend.types = [...backend.types, {...carType, identifier: 'shop:sports', parent: 'shop:car'}, {...carType, identifier: 'shop:racer', parent: 'shop:sports'}];
    const made = new FakeContext(backend);
    await made.service(TypeStore).ready;

    return made;
}

describe('Editors', () => {
    it('opens an entry in the editor of its type, else of its nearest ancestor, else the generic one', async () => {
        const made = await context();
        made.editors.instances.register({type: 'shop:car', tab: CAR_TAB});
        made.editors.instances.register({type: 'shop:sports', tab: SPORTS_TAB});

        for (const type of ['shop:car', 'shop:racer', 'shop:bike']) {
            made.editors.openInstance(made, {id: 'c001', type, title: 'Alpha'}, 'reuse');
        }

        expect(made.workspace.opened.map((tab) => tab.type)).toEqual([CAR_TAB, SPORTS_TAB, Editors.ENTITY_TAB]);
        expect(made.workspace.opened[0]).toMatchObject({title: 'Alpha', params: {id: 'c001', type: 'shop:car'}, key: 'c001'});
    });

    it('tells an asset only by an editor registered, never the generic one', async () => {
        const made = await context();
        made.editors.instances.register({type: 'shop:car', tab: CAR_TAB});

        expect(made.editors.instances.of('shop:racer', made.service(TypeStore).catalog)?.tab).toBe(CAR_TAB);
        expect(made.editors.instances.of('shop:bike', made.service(TypeStore).catalog)).toBeNull();
    });

    it('opens a type in the editor of what it extends, else the generic one; two for one type are refused', async () => {
        const made = await context();
        made.editors.types.register({type: 'shop:car', tab: CAR_TAB});

        made.editors.openType(made, 'shop:sports', 'new');
        made.editors.openType(made, 'shop:bike', 'new');

        expect(made.workspace.opened.map((tab) => [tab.type, tab.params])).toEqual([[CAR_TAB, {type: 'shop:sports'}], [Editors.TYPE_TAB, {type: 'shop:bike'}]]);
        expect(() => made.editors.types.register({type: 'shop:car', tab: SPORTS_TAB})).toThrow('Two editors of types');
    });
});
