import {describe, expect, it} from 'vitest';
import {FakeBackend, FixedAnswer, storeOf} from '../../FakeBackend.ts';
import type {SchemaDraft} from '../../../src/schema/draft/SchemaDraft.ts';
import type {PreviewOutcome} from '../../../src/schema/save/PreviewOutcome.ts';
import type {SchemaPreview} from '../../../src/schema/save/SchemaPreview.ts';
import {SchemaSession} from '../../../src/schema/save/SchemaSession.ts';
import type {UnsavedSchemaAnswer, UnsavedSchemaQuestion} from '../../../src/schema/save/UnsavedSchemaQuestion.ts';
import type {UnsavedAnswer} from '../../../src/public/entities/UnsavedQuestion.ts';
import {int, shopSchema, text, type} from '../draft/ShopSchema.ts';

/** An UnsavedSchemaQuestion answering `answer`, and whether a review saved: `reviewSaves`. */
class FixedSchemaAnswer implements UnsavedSchemaQuestion {
    public asked = 0;
    public reviewed = 0;

    public constructor(private readonly answer: UnsavedSchemaAnswer, private readonly reviewSaves = false) {
    }

    public ask(): Promise<UnsavedSchemaAnswer> {
        this.asked++;

        return Promise.resolve(this.answer);
    }

    public review(): Promise<boolean> {
        this.reviewed++;

        return Promise.resolve(this.reviewSaves);
    }
}

/** A session over the shop, at revision 4, its entity editors answered with `entityAnswer`. */
async function shop(question: UnsavedSchemaQuestion = new FixedSchemaAnswer('cancel'), entityAnswer: UnsavedAnswer = 'discard'): Promise<{backend: FakeBackend; session: SchemaSession}> {
    const backend = new FakeBackend();
    backend.schema = shopSchema;
    const session = await SchemaSession.load(backend.client(), storeOf(backend, new FixedAnswer(entityAnswer)), question);

    return {backend, session};
}

/** The draft's boat renamed to a skiff. */
function renameBoat(draft: SchemaDraft): void {
    draft.renameType(draft.typeNamed('shop:boat')!.id, 'shop:skiff');
}

/** The preview `outcome` gives; fails the test when it gives none. */
function previewOf(outcome: PreviewOutcome): SchemaPreview {
    if (outcome.kind !== 'previewed') {
        throw new Error(`Expected a preview, got "${outcome.kind}".`);
    }

    return outcome.preview;
}

describe('SchemaSession', () => {
    it('previews the written draft, saves that, then loads the types and a fresh draft', async () => {
        const {backend, session} = await shop();
        const replaced: SchemaDraft[] = [];
        session.onReplaced((draft) => replaced.push(draft));
        const before = session.draft;
        renameBoat(before);
        expect(session.hasUnsaved).toBe(true);

        const preview = previewOf(await session.preview());
        expect(preview.report.operations).toEqual([{op: 'renameTypes', renames: {'shop:boat': 'shop:skiff'}}]);
        const typesRead = backend.count('coconut-cms:types.list');

        const outcome = await session.save(preview);
        expect(outcome.kind).toBe('saved');
        expect(backend.calls.find((call) => call.operation === 'coconut-cms:schema.save')?.input).toEqual({revision: 4, operations: preview.save.operations, acceptBreaks: false});
        expect(backend.count('coconut-cms:types.list'), 'The types load again.').toBe(typesRead + 1);
        expect(session.draft).not.toBe(before);
        expect(replaced).toEqual([session.draft]);
        expect(session.draft.revision).toBe(5);
        expect(session.draft.history.canUndo(), 'What it was is no longer stored: nothing to undo.').toBe(false);
        expect(session.hasUnsaved).toBe(false);
    });

    it('sends nothing for a draft as loaded, or one with problems', async () => {
        const {backend, session} = await shop();
        expect(await session.preview()).toEqual({kind: 'unchanged'});

        const length = session.draft.fieldNamed(session.draft.typeNamed('shop:boat')!.id, 'length')!.id;
        session.draft.retypeField(length, text, {value: ''});
        const outcome = await session.preview();
        expect(outcome.kind).toBe('problems');
        expect(backend.count('coconut-cms:schema.preview')).toBe(0);
    });

    it('accepts the breaks a preview listed, and tells a preview the server would refuse', async () => {
        const {backend, session} = await shop();
        renameBoat(session.draft);
        backend.findings = {breaks: [{type: 'shop:yacht', description: 'Loses its parent.', setAside: 'MoveAside(shop:yacht)'}]};

        const preview = previewOf(await session.preview());
        expect([preview.breaks, preview.refused]).toEqual([true, false]);
        await session.save(preview);
        expect(backend.calls.find((call) => call.operation === 'coconut-cms:schema.save')?.input['acceptBreaks']).toBe(true);

        backend.findings = {failures: {c001: 'Not a number.'}};
        renameBoat(session.draft);
        expect(previewOf(await session.preview()).refused).toBe(true);
    });

    it('previews again when the server refuses the save', async () => {
        const {backend, session} = await shop();
        renameBoat(session.draft);
        const preview = previewOf(await session.preview());
        backend.refuseNextSave = true;
        backend.findings = {duplicates: [{type: 'shop:maker', values: {title: 'Ada'}, entities: ['c001', 'c002']}]};

        const outcome = await session.save(preview);
        expect(outcome.kind).toBe('refused');
        expect(outcome.kind === 'refused' && previewOf(outcome.outcome).refused).toBe(true);
        expect(session.hasUnsaved, 'The draft is kept, to fix.').toBe(true);
    });

    it('lets participants act before a save is sent and once it ran; one throwing before cancels it', async () => {
        const {backend, session} = await shop();
        const told: string[] = [];
        session.participate({
            beforeSave: (save) => {
                told.push(`before ${save.operations.length}, ${backend.count('coconut-cms:schema.save')} sent`);

                return Promise.resolve();
            },
            afterSave: (report) => {
                told.push(`after revision ${report.revision}`);

                return Promise.reject(new Error('Its own to recover.'));
            },
        });
        renameBoat(session.draft);

        expect((await session.save(previewOf(await session.preview()))).kind).toBe('saved');
        expect(told).toEqual(['before 1, 0 sent', 'after revision 5']);

        session.participate({beforeSave: () => Promise.reject(new Error('Not now.')), afterSave: () => Promise.resolve()});
        renameBoat(session.draft);
        await expect(session.save(previewOf(await session.preview()))).rejects.toThrow('Not now.');
        expect(backend.count('coconut-cms:schema.save')).toBe(1);
    });

    it('tells a stale draft, and reloads it dropping its edits', async () => {
        const {backend, session} = await shop();
        renameBoat(session.draft);
        backend.schema = {...shopSchema, revision: 5};

        expect(await session.preview()).toEqual({kind: 'stale'});
        await session.reload();
        expect([session.draft.revision, session.hasUnsaved]).toEqual([5, false]);
    });

    it('settles the entity editors on the types it changes first: one cancelled sends nothing', async () => {
        const backend = new FakeBackend();
        backend.schema = {revision: 1, types: [type('shop:car', {fields: {name: text, power: int}, defaults: {name: '', power: 90}})]};
        const store = storeOf(backend, new FixedAnswer('cancel'));
        const hold = store.edit(backend.addCar('Alpha'));
        await hold.session.ready;
        hold.session.set('power', 1);
        const session = await SchemaSession.load(backend.client(), store, new FixedSchemaAnswer('cancel'));
        const draft = session.draft;
        draft.renameField(draft.fieldNamed(draft.typeNamed('shop:car')!.id, 'power')!.id, 'horsepower');

        expect(await session.save(previewOf(await session.preview()))).toEqual({kind: 'cancelled'});
        expect(backend.count('coconut-cms:schema.save')).toBe(0);
    });

    it('guards signing out: asks only with unsaved edits, then reviews, discards or stays', async () => {
        const clean = new FixedSchemaAnswer('cancel');
        expect(await (await shop(clean)).session.allowsSignOut()).toBe(true);
        expect(clean.asked).toBe(0);

        const staying = await shop(new FixedSchemaAnswer('cancel'));
        renameBoat(staying.session.draft);
        expect(await staying.session.allowsSignOut()).toBe(false);

        const discarding = await shop(new FixedSchemaAnswer('discard'));
        renameBoat(discarding.session.draft);
        expect(await discarding.session.allowsSignOut()).toBe(true);
        expect(discarding.session.hasUnsaved).toBe(false);

        for (const saves of [true, false]) {
            const question = new FixedSchemaAnswer('review', saves);
            const reviewing = await shop(question);
            renameBoat(reviewing.session.draft);
            expect(await reviewing.session.allowsSignOut()).toBe(saves);
            expect(question.reviewed).toBe(1);
        }
        expect(SchemaSession.GUARD).toEqual({name: 'coconut-cms:schema', before: ['coconut-cms:entities']});
    });
});
