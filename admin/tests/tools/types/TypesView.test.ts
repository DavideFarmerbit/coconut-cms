// @vitest-environment happy-dom
import {describe, expect, it} from 'vitest';
import {FakeBackend} from '../../FakeBackend.ts';
import {FakeContext} from '../../FakeContext.ts';
import {SchemaEditing} from '../../../src/tools/schema/SchemaEditing.ts';
import {TypesView} from '../../../src/tools/types/TypesView.ts';
import {shopSchema} from '../../schema/draft/ShopSchema.ts';

/** Lets pending promises run. */
async function settled(): Promise<void> {
    for (let round = 0; round < 10; round++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

/** What each entry of `view` reads, with its marks. */
function entries(view: TypesView): string[] {
    return [...view.element.querySelectorAll('.list__item')].map((item) => {
        const marks = ['is-created', 'is-renamed', 'is-deleted'].filter((mark) => item.classList.contains(mark));

        return [item.textContent, ...marks].join(' ');
    });
}

describe('TypesView', () => {
    it('lists the types the user may read to one without the schema-editor role', async () => {
        const backend = new FakeBackend();
        backend.schemaDenied = true;
        const view = new TypesView(new FakeContext(backend));
        await settled();

        expect(entries(view)).toEqual(['bike', 'car', 'wheel']);
        expect(view.element.textContent).not.toContain('New type');
    });

    it('lists the draft to a schema editor, marking what it creates, renames and deletes, as it changes', async () => {
        const backend = new FakeBackend();
        backend.schema = shopSchema;
        const context = new FakeContext(backend);
        const view = new TypesView(context);
        await settled();
        expect(entries(view)).toEqual(['boat', 'engine', 'maker', 'sail', 'vehicle', 'yacht']);

        const draft = (await context.service(SchemaEditing).session())!.draft;
        draft.renameType(draft.typeNamed('shop:boat')!.id, 'shop:skiff');
        draft.createType('shop:dinghy', null);
        draft.deleteType(draft.typeNamed('shop:yacht')!.id);

        expect(entries(view)).toEqual(['dinghynew is-created', 'engine', 'maker', 'sail', 'skiffrenamed is-renamed', 'vehicle', 'yacht is-deleted']);
        expect(view.history).toBe(draft.history);
    });

    it('opens a type\'s inspector by its identifier now', async () => {
        const backend = new FakeBackend();
        backend.schema = shopSchema;
        const context = new FakeContext(backend);
        const view = new TypesView(context);
        await settled();

        view.element.querySelector<HTMLElement>('[data-type="shop:sail"]')!.click();
        expect(context.workspace.opened.map((tab) => tab.params)).toEqual([{type: 'shop:sail'}]);
    });
});
