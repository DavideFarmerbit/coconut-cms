// @vitest-environment happy-dom
import {afterEach, describe, expect, it} from 'vitest';
import {FakeBackend, storeOf} from '../../FakeBackend.ts';
import {SchemaSession} from '../../../src/schema/save/SchemaSession.ts';
import type {UnsavedSchemaAnswer, UnsavedSchemaQuestion} from '../../../src/schema/save/UnsavedSchemaQuestion.ts';
import {SchemaPreviewDialog} from '../../../src/tools/schema/SchemaPreviewDialog.ts';
import {shopSchema} from '../../schema/draft/ShopSchema.ts';

/** A question never asked here. */
const unasked: UnsavedSchemaQuestion = {
    ask: (): Promise<UnsavedSchemaAnswer> => Promise.resolve('cancel'),
    review: (): Promise<boolean> => Promise.resolve(false),
};

/** A session over the shop, its boat renamed to a skiff. */
async function edited(): Promise<{backend: FakeBackend; session: SchemaSession}> {
    const backend = new FakeBackend();
    backend.schema = shopSchema;
    const session = await SchemaSession.load(backend.client(), storeOf(backend), unasked);
    session.draft.renameType(session.draft.typeNamed('shop:boat')!.id, 'shop:skiff');

    return {backend, session};
}

/** Lets pending promises run, as many rounds as `rounds`. */
async function settled(rounds = 10): Promise<void> {
    for (let round = 0; round < rounds; round++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

/** The dialog's button reading `label`; fails when there's none. */
function button(label: string): HTMLButtonElement {
    const found = [...document.querySelectorAll<HTMLButtonElement>('.dialog__buttons button')].find((each) => each.textContent === label);
    if (found === undefined) {
        throw new Error(`No button "${label}": ${[...document.querySelectorAll('.dialog__buttons button')].map((each) => each.textContent).join(', ')}.`);
    }

    return found;
}

describe('SchemaPreviewDialog', () => {
    afterEach(() => document.body.replaceChildren());

    it('shows the report, saves on confirming, and closes', async () => {
        const {backend, session} = await edited();
        backend.findings = {fixups: ['RenameType(shop:boat → shop:skiff)']};
        const reviewed = SchemaPreviewDialog.review(session);
        await settled();

        expect(document.querySelector('.dialog__body')?.textContent).toContain('RenameType(shop:boat → shop:skiff)');
        button('Save').click();

        expect(await reviewed).toBe(true);
        expect(backend.count('coconut-cms:schema.save')).toBe(1);
        expect(document.querySelector('.dialog')).toBeNull();
    });

    it('accepts breaks by name, and offers no save the server would refuse', async () => {
        const {backend, session} = await edited();
        backend.findings = {breaks: [{type: 'shop:yacht', description: 'Loses a field.', setAside: null}], failures: {c001: 'Not a number.'}};
        const reviewed = SchemaPreviewDialog.review(session);
        await settled();

        expect(button('Accept breaks and save').disabled).toBe(true);
        expect(document.querySelector('.dialog__body')?.textContent).toContain('Entry c001 can\'t move: Not a number.');
        button('Cancel').click();
        expect(await reviewed).toBe(false);
    });

    it('offers a reload to a stale draft, which settles it by dropping the edits', async () => {
        const {backend, session} = await edited();
        backend.schema = {...shopSchema, revision: 5};
        const reviewed = SchemaPreviewDialog.review(session);
        await settled();

        button('Reload, dropping my edits').click();
        expect(await reviewed).toBe(true);
        expect(session.hasUnsaved).toBe(false);
    });

    it('closes on Escape, saving nothing', async () => {
        const {backend, session} = await edited();
        const reviewed = SchemaPreviewDialog.review(session);
        await settled();

        document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape'}));
        expect(await reviewed).toBe(false);
        expect(backend.count('coconut-cms:schema.save')).toBe(0);
    });
});
