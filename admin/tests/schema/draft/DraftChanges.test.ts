import {describe, expect, it} from 'vitest';
import {DraftChanges} from '../../../src/schema/draft/DraftChanges.ts';
import {int, shopDraft, text} from './ShopSchema.ts';

describe('DraftChanges', () => {
    it('lists nothing for a draft as loaded, or one whose edits were undone', () => {
        const {draft, id} = shopDraft();
        expect(DraftChanges.of(draft)).toEqual([]);

        draft.renameType(id('shop:boat'), 'shop:skiff');
        draft.history.undo();
        expect(DraftChanges.of(draft)).toEqual([]);
    });

    it('tells each change by type, in words, fields after the type', () => {
        const {draft, id, field} = shopDraft();
        const length = field('shop:boat', 'length');
        draft.renameType(id('shop:boat'), 'shop:skiff');
        draft.renameField(length, 'size');
        draft.retypeField(field('shop:maker', 'title'), {...text, nullable: true}, {value: ''});
        draft.addField(id('shop:maker'), 'founded', int, {value: 1900});
        draft.createType('shop:dinghy', id('shop:skiff'));
        draft.deleteType(id('shop:yacht'));

        expect(DraftChanges.of(draft)).toEqual([
            {type: id('shop:maker'), title: 'shop:maker', changes: ['Field title made optional.', 'Field founded added.']},
            {type: id('shop:skiff'), title: 'shop:skiff', changes: ['Renamed from shop:boat.', 'Field length renamed to size.']},
            {type: expect.any(String), title: 'shop:yacht', changes: ['Deleted, with its own fields.']},
            {type: id('shop:dinghy'), title: 'shop:dinghy', changes: ['Created, extending shop:skiff.']},
        ]);
    });
});
