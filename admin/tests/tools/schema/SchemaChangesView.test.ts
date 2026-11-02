// @vitest-environment happy-dom
import {afterEach, describe, expect, it} from 'vitest';
import {FakeBackend} from '../../FakeBackend.ts';
import {FakeContext} from '../../FakeContext.ts';
import type {SchemaDraft} from '../../../src/schema/draft/SchemaDraft.ts';
import {SchemaChangesView} from '../../../src/tools/schema/SchemaChangesView.ts';
import {SchemaEditing} from '../../../src/tools/schema/SchemaEditing.ts';
import {shopSchema} from '../../schema/draft/ShopSchema.ts';

/** Lets pending promises run. */
async function settled(): Promise<void> {
    for (let round = 0; round < 10; round++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

/** The panel over the shop's draft, with that draft. */
async function panel(): Promise<{view: SchemaChangesView; draft: SchemaDraft; backend: FakeBackend}> {
    const backend = new FakeBackend();
    backend.schema = shopSchema;
    const context = new FakeContext(backend);
    const view = new SchemaChangesView(context);
    await settled();

    return {view, draft: (await context.service(SchemaEditing).session())!.draft, backend};
}

/** The panel's button reading `label`. */
function button(view: SchemaChangesView, label: string): HTMLButtonElement {
    return [...view.element.querySelectorAll<HTMLButtonElement>('button')].find((each) => each.textContent === label)!;
}

describe('SchemaChangesView', () => {
    afterEach(() => document.body.replaceChildren());

    it('has nothing to save for a draft as loaded', async () => {
        const {view} = await panel();

        expect(view.element.textContent).toContain('No changes');
        expect(button(view, 'Save…').disabled).toBe(true);
    });

    it('lists each change by type as it\'s made, and what keeps the draft from saving', async () => {
        const {view, draft} = await panel();
        draft.renameType(draft.typeNamed('shop:boat')!.id, 'shop:skiff');
        draft.retypeField(draft.fieldNamed(draft.typeNamed('shop:maker')!.id, 'title')!.id, {kind: 'scalar', nullable: false, scalarType: 'int'}, {value: 0});

        expect(view.element.textContent).toContain('Renamed from shop:boat.');
        expect(view.element.textContent).toContain('shop:maker: Choose how "title" converts its stored values, or reset them.');
        expect(button(view, 'Save…').disabled).toBe(false);

        draft.history.undo();
        expect(view.element.textContent).not.toContain('Choose how');
    });

    it('discards every edit once confirmed, loading the draft again', async () => {
        const {view, draft, backend} = await panel();
        draft.renameType(draft.typeNamed('shop:boat')!.id, 'shop:skiff');

        button(view, 'Discard').click();
        await settled();
        [...document.querySelectorAll<HTMLButtonElement>('.dialog__buttons button')].find((each) => each.textContent === 'Discard')!.click();
        await settled();

        expect(view.element.textContent).toContain('No changes');
        expect(backend.count('coconut-cms:schema.get')).toBe(2);
    });
});
