import {describe, expect, it} from 'vitest';
import type {EntitySession} from '../../../src/public/entities/EntitySession.ts';
import type {SessionChange} from '../../../src/public/entities/SessionChange.ts';
import {FakeBackend, storeOf} from '../../FakeBackend.ts';

/** A ready session of a car named Alpha, with one 17" wheel, and its backend. */
async function car(): Promise<{session: EntitySession; backend: FakeBackend}> {
    const backend = new FakeBackend();
    const id = backend.addCar('Alpha');
    const session = storeOf(backend).edit(id).session;
    await session.ready;

    return {session, backend};
}

/** Undoable changes to an entity: every change a session makes is an edit in its history. */
describe('EntitySession history', () => {
    it('undoes a value typed letter by letter as one edit, until the field is left', async () => {
        const {session} = await car();
        session.set('name', 'B');
        session.set('name', 'Be');
        session.set('name', 'Beta');

        expect(session.history.undoLabel()).toBe('Change name');
        session.history.undo();
        expect(session.valueAt('name')).toBe('Alpha');
        expect(session.hasEdits, 'Undone, nothing is left to save.').toBe(false);

        session.history.redo();
        expect(session.valueAt('name')).toBe('Beta');

        session.history.seal();
        session.set('name', 'Gamma');
        session.history.undo();
        expect(session.valueAt('name'), 'Typed after leaving the field, it\'s an edit of its own.').toBe('Beta');
    });

    it('keeps two fields apart', async () => {
        const {session} = await car();
        session.set('name', 'Beta');
        session.set('power', 120);

        session.history.undo();
        expect([session.valueAt('name'), session.valueAt('power')]).toEqual(['Beta', 100]);
    });

    it('brings back the items removed, as the items saved: none is new', async () => {
        const {session} = await car();
        session.itemsAt('wheels')!.clear();
        expect(session.valueAt('wheels')).toEqual([]);
        expect(session.history.undoLabel()).toBe('Remove every item from wheels');

        session.history.undo();

        expect(session.itemsAt('wheels')!.values).toHaveLength(1);
        expect(session.statusAt('wheels.0').isNew).toBe(false);
        expect(session.valueAt('wheels.0:size')).toBe(17);
        expect(session.hasEdits).toBe(false);
    });

    it('undoes a move, keeping where each item came from', async () => {
        const {session} = await car();
        const wheels = session.itemsAt('wheels')!;
        wheels.duplicate(0);
        wheels.move(1, 0);
        expect(session.statusAt('wheels.0').isNew).toBe(true);

        session.history.undo();
        expect(session.statusAt('wheels.0').isNew, 'Back where it was: the saved wheel first.').toBe(false);
        expect(session.statusAt('wheels.1').isNew).toBe(true);
        session.history.undo();
        expect(session.itemsAt('wheels')!.values).toHaveLength(1);
    });

    it('undoes discarding the changes, bringing them back', async () => {
        const {session} = await car();
        session.set('name', 'Beta');
        session.revert();
        expect(session.valueAt('name')).toBe('Alpha');

        session.history.undo();
        expect(session.valueAt('name')).toBe('Beta');
        expect(session.hasEdits).toBe(true);
    });

    it('tells its views the change again, from no view, when undone', async () => {
        const {session} = await car();
        const view = {};
        session.set('name', 'Beta', view);
        session.itemsAt('wheels', view)!.clear();
        const told: SessionChange[] = [];
        session.onChange((change) => told.push(change));

        session.history.undo();
        session.history.undo();

        expect(told).toEqual([
            {kind: 'shape', path: 'wheels', source: null}, {kind: 'state'},
            {kind: 'values', paths: ['name'], source: null}, {kind: 'state'},
        ]);
    });

    it('forgets every edit once saved', async () => {
        const {session} = await car();
        session.set('name', 'Beta');

        expect(await session.save()).toBe(true);
        expect(session.history.canUndo()).toBe(false);
    });
});
