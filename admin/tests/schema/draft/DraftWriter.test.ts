import {describe, expect, it} from 'vitest';
import {DefaultPath} from '../../../src/schema/draft/DefaultPath.ts';
import {DraftWriter} from '../../../src/schema/draft/DraftWriter.ts';
import {int, shopDraft, text} from './ShopSchema.ts';

describe('DraftWriter', () => {
    it('writes nothing for a draft left as loaded, at the revision it loaded', () => {
        const {draft} = shopDraft();

        expect(DraftWriter.write(draft)).toEqual({revision: 4, operations: [], problems: []});
    });

    it('writes renames at once, whatever the edits: a swap needs no other name', () => {
        const {draft, id, field} = shopDraft();
        const length = field('shop:boat', 'length');
        const sail = field('shop:boat', 'sail');
        draft.renameField(length, 'spare');
        draft.renameField(sail, 'length');
        draft.renameField(length, 'sail');
        draft.renameType(id('shop:maker'), 'shop:builder');

        expect(DraftWriter.write(draft).operations).toEqual([
            {op: 'renameTypes', renames: {'shop:maker': 'shop:builder'}},
            {op: 'renameFields', type: 'shop:boat', renames: {length: 'sail', sail: 'length'}},
        ]);
    });

    it('clears cross-field rules first when a field they name changes, and sets them last', () => {
        const {draft, id, field} = shopDraft();
        draft.renameField(field('shop:yacht', 'cabins'), 'berths');
        draft.setPrototypeRules(id('shop:yacht'), [{rule: 'shop:cabins-fit', config: {field: 'berths'}}]);

        expect(DraftWriter.write(draft).operations).toEqual([
            {op: 'setPrototypeRules', type: 'shop:yacht', rules: []},
            {op: 'renameFields', type: 'shop:yacht', renames: {cabins: 'berths'}},
            {op: 'setPrototypeRules', type: 'shop:yacht', rules: [{rule: 'shop:cabins-fit', config: {field: 'berths'}}]},
        ]);
    });

    it('frees a name before it\'s taken again, and creates a type before what needs it', () => {
        const {draft, id} = shopDraft();
        draft.deleteType(id('shop:yacht'));
        draft.addField(id('shop:boat'), 'cabins', int, {value: 0});
        draft.createType('shop:dinghy', id('shop:boat'));

        expect(DraftWriter.write(draft).operations).toEqual([
            {op: 'deleteType', type: 'shop:yacht'},
            {op: 'createType', type: 'shop:dinghy', parent: 'shop:boat'},
            {op: 'addField', type: 'shop:boat', field: {name: 'cabins', ...int}, default: 0},
        ]);
    });

    it('deletes a type only once what leaves it has left', () => {
        const {draft, id, field} = shopDraft();
        draft.moveField(field('shop:yacht', 'cabins'), id('shop:boat'), {value: 2});
        draft.deleteType(id('shop:yacht'));

        expect(DraftWriter.write(draft).operations).toEqual([
            {op: 'moveField', type: 'shop:yacht', field: 'cabins', to: 'shop:boat', default: 2},
            {op: 'deleteType', type: 'shop:yacht'},
        ]);
    });

    it('reparents along the final tree, parents first: two types trading places make no loop', () => {
        const {draft, id} = shopDraft();
        draft.reparent(id('shop:yacht'), null);
        draft.reparent(id('shop:boat'), id('shop:yacht'));

        expect(DraftWriter.write(draft).operations).toEqual([
            {op: 'reparent', type: 'shop:yacht', parent: null},
            {op: 'reparent', type: 'shop:boat', parent: 'shop:yacht'},
        ]);
    });

    it('tells a retype from a nullability change, and asks for a conversion not chosen', () => {
        const {draft, field} = shopDraft();
        const length = field('shop:boat', 'length');
        draft.retypeField(length, text, {value: 'twelve'});
        draft.retypeField(field('shop:maker', 'title'), {...text, nullable: true}, {value: ''});

        const unchosen = DraftWriter.write(draft);
        expect(unchosen.problems).toEqual([{message: 'Choose how "length" converts its stored values, or reset them.', type: null, field: length}]);
        expect(unchosen.operations[0]).toEqual({op: 'makeOptional', type: 'shop:maker', field: 'title'});
        expect(unchosen.operations.map((operation) => operation.op)).not.toContain('retypeField');

        draft.chooseRetype(length, {kind: 'coconut-cms:number-to-text', parameters: {}});
        expect(DraftWriter.write(draft)).toMatchObject({
            problems: [],
            operations: [
                {op: 'makeOptional', type: 'shop:maker', field: 'title'},
                {op: 'retypeField', type: 'shop:boat', field: {name: 'length', ...text}, converter: {kind: 'coconut-cms:number-to-text'}, default: 'twelve'},
            ],
        });
    });

    it('writes rules, permissions, groups and defaults that changed, with the final names', () => {
        const {draft, id, field} = shopDraft();
        const area = DefaultPath.of(field('shop:boat', 'sail'), field('shop:sail', 'area'));
        draft.removeUniqueGroup(id('shop:maker'), [field('shop:maker', 'title')]);
        draft.setFieldPermission(field('shop:yacht', 'cabins'), {read: null, write: 'captain'});
        draft.setTypePermission(id('shop:yacht'), {read: null, create: 'captain', update: null, delete: null});
        draft.setDefault(id('shop:boat'), area, 25);
        draft.inheritDefault(id('shop:yacht'), area);
        draft.renameField(field('shop:boat', 'sail'), 'canvas');

        expect(DraftWriter.write(draft).operations).toEqual([
            {op: 'removeUniqueGroup', type: 'shop:maker', fields: ['title']},
            {op: 'renameFields', type: 'shop:boat', renames: {sail: 'canvas'}},
            {op: 'setFieldPermission', type: 'shop:yacht', field: 'cabins', read: null, write: 'captain'},
            {op: 'setTypePermission', type: 'shop:yacht', read: null, create: 'captain', update: null, delete: null},
            {op: 'setDefault', type: 'shop:boat', path: 'canvas:area', value: 25},
            {op: 'inheritDefault', type: 'shop:yacht', path: 'canvas:area'},
        ]);
    });
});
