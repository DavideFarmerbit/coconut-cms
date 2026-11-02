import {describe, expect, it} from 'vitest';
import {DefaultPath} from '../../../src/schema/draft/DefaultPath.ts';
import {EditRefused} from '../../../src/schema/draft/EditRefused.ts';
import {SchemaDraft} from '../../../src/schema/draft/SchemaDraft.ts';
import {int, shopDraft as draft, text, type} from './ShopSchema.ts';

/** The defaults `identifier` sets itself now, by path, as a save writes them. */
function defaultsOf(loaded: SchemaDraft, identifier: string): Record<string, unknown> {
    const now = loaded.typeNamed(identifier)!.now!;

    return Object.fromEntries([...now.defaults.values()].map((pinned) => [loaded.pathText(pinned.path), pinned.value]));
}

describe('SchemaDraft', () => {
    it('loads every type and field as an object knowing what it was', () => {
        const {draft: loaded, id} = draft();

        expect(loaded.revision).toBe(4);
        expect(loaded.chainOf(id('shop:yacht')).map((each) => each.now!.identifier)).toEqual(['shop:yacht', 'shop:boat', 'shop:vehicle']);
        expect(loaded.fieldNamed(id('shop:yacht'), 'name')?.now?.owner).toBe(id('shop:vehicle'));
        expect(loaded.fieldsOf(id('shop:boat')).map((each) => each.now!.name)).toEqual(['length', 'sail', 'maker']);
        expect(loaded.typeNamed('shop:boat')!.was).toBe(loaded.typeNamed('shop:boat')!.now);
        expect(defaultsOf(loaded, 'shop:yacht')).toEqual({cabins: 2, 'sail:area': 30});
    });

    it('holds a default by the fields it goes through: a rename anywhere along it follows', () => {
        const {draft: loaded, field} = draft();
        const area = field('shop:sail', 'area');

        loaded.renameField(area, 'size');
        expect(defaultsOf(loaded, 'shop:yacht')).toEqual({cabins: 2, 'sail:size': 30});
        expect(defaultsOf(loaded, 'shop:boat')).toMatchObject({'sail:size': 20});

        loaded.history.undo();
        expect(defaultsOf(loaded, 'shop:yacht')).toEqual({cabins: 2, 'sail:area': 30});
    });

    it('creates, renames and deletes types, each undoable', () => {
        const {draft: loaded, id} = draft();
        const buoy = loaded.createType('shop:buoy', null);
        loaded.renameType(buoy, 'shop:marker');
        expect(loaded.typeNamed('shop:marker')?.was).toBeNull();

        loaded.history.undo();
        expect(loaded.typeNamed('shop:buoy')).toBeDefined();
        loaded.history.undo();
        expect(loaded.type(buoy), 'Its creation undone, it was never there.').toBeUndefined();

        loaded.deleteType(id('shop:yacht'));
        expect(loaded.typeNamed('shop:yacht')).toBeUndefined();
        expect(loaded.everything().fields.filter((each) => each.now === null).map((each) => each.was!.name)).toEqual(['cabins']);
        loaded.history.undo();
        expect(loaded.typeNamed('shop:yacht')?.now?.fields).toHaveLength(1);
    });

    it('refuses what the server would, changing nothing', () => {
        const {draft: loaded, id, field} = draft();
        const refusals: [string, () => unknown][] = [
            ['declared by the code', () => loaded.renameType(id('shop:vehicle'), 'shop:car')],
            ['declared by the code', () => loaded.addField(id('shop:engine'), 'rpm', int, {value: 0})],
            ['doesn\'t allow editor-made subtypes', () => loaded.createType('shop:turbo', id('shop:engine'))],
            ['already exists', () => loaded.renameType(id('shop:boat'), 'shop:sail')],
            ['no "namespace:key"', () => loaded.createType('buoy', null)],
            ['already has a field "name"', () => loaded.addField(id('shop:yacht'), 'name', text, {value: ''})],
            // A subtype holds it.
            ['already has a field "cabins"', () => loaded.addField(id('shop:boat'), 'cabins', int, {value: 0})],
            ['no field name', () => loaded.renameField(field('shop:boat', 'length'), '__length')],
            ['needs a default', () => loaded.addField(id('shop:boat'), 'beam', int, null)],
            ['a list of its items', () => loaded.addField(id('shop:boat'), 'tags', {kind: 'collection', nullable: false, item: text}, {value: 'sea'})],
            ['extends "shop:boat"', () => loaded.deleteType(id('shop:boat'))],
            ['one of its own subtypes', () => loaded.reparent(id('shop:boat'), id('shop:yacht'))],
        ];

        for (const [message, attempt] of refusals) {
            expect(attempt, message).toThrow(EditRefused);
            expect(attempt, message).toThrow(message);
        }
        expect(loaded.history.canUndo()).toBe(false);
    });

    it('refuses a new type taking a name one had when the draft loaded', () => {
        const {draft: loaded, id} = draft();
        const boat = id('shop:boat');
        loaded.renameType(id('shop:maker'), 'shop:builder');

        expect(() => loaded.createType('shop:maker', null)).toThrow('save its rename or delete first');
        loaded.renameType(boat, 'shop:maker');
        expect(loaded.typeNamed('shop:maker')?.id, 'Renaming one to it is a swap the save writes in turn.').toBe(boat);
    });

    it('re-points every field naming a type it renames, and refuses a deleted type\'s name', () => {
        const {draft: loaded, id, field} = draft();
        loaded.renameType(id('shop:maker'), 'shop:builder');
        expect(loaded.field(field('shop:boat', 'maker'))!.now!.shape.target).toBe('shop:builder');
        loaded.renameType(id('shop:sail'), 'shop:canvas');
        expect(defaultsOf(loaded, 'shop:yacht'), 'A default through the Embed still reads.').toEqual({cabins: 2, 'sail:area': 30});

        loaded.history.undo();
        loaded.history.undo();
        expect(loaded.field(field('shop:boat', 'maker'))!.now!.shape.target).toBe('shop:maker');

        loaded.deleteType(id('shop:yacht'));
        expect(() => loaded.renameType(id('shop:maker'), 'shop:yacht')).toThrow('save the delete first');
    });

    it('renames what a type reference names with the type: its base, and the defaults naming it', () => {
        const kind = {kind: 'type_reference', nullable: true, target: 'shop:boat'} as const;
        const kinds = {kind: 'collection', nullable: false, item: {...kind, nullable: false}} as const;
        const loaded = SchemaDraft.load({revision: 1, types: [
            type('shop:boat', {}),
            type('shop:yacht', {parent: 'shop:boat'}),
            type('shop:harbor', {fields: {docks: kind, allows: kinds}, defaults: {docks: 'shop:yacht', allows: 2, 'allows.0': 'shop:boat', 'allows.1': 'shop:yacht'}}),
        ]});
        const harbor = loaded.typeNamed('shop:harbor')!.id;

        loaded.renameType(loaded.typeNamed('shop:yacht')!.id, 'shop:ketch');
        loaded.renameType(loaded.typeNamed('shop:boat')!.id, 'shop:ship');

        expect(defaultsOf(loaded, 'shop:harbor')).toEqual({docks: 'shop:ketch', allows: ['shop:ship', 'shop:ketch']});
        expect(loaded.fieldNamed(harbor, 'docks')!.now!.shape.target).toBe('shop:ship');
        expect(loaded.fieldNamed(harbor, 'allows')!.now!.shape.item?.target).toBe('shop:ship');
        loaded.history.undo();
        loaded.history.undo();
        expect(defaultsOf(loaded, 'shop:harbor')).toEqual({docks: 'shop:yacht', allows: ['shop:boat', 'shop:yacht']});
    });

    it('holds a stored Collection\'s default as one list', () => {
        const tags = {kind: 'collection', nullable: false, item: text} as const;
        const loaded = SchemaDraft.load({revision: 1, types: [type('shop:flag', {fields: {tags}, defaults: {tags: 2, 'tags.0': 'sea', 'tags.1': 'night'}})]});

        expect(defaultsOf(loaded, 'shop:flag')).toEqual({tags: ['sea', 'night']});
    });

    it('moves a type: it and its subtypes lose the defaults through fields they no longer inherit', () => {
        const {draft: loaded, id} = draft();
        loaded.reparent(id('shop:yacht'), id('shop:vehicle'));
        expect(defaultsOf(loaded, 'shop:yacht')).toEqual({cabins: 2});

        loaded.history.undo();
        expect(defaultsOf(loaded, 'shop:yacht')).toEqual({cabins: 2, 'sail:area': 30});

        loaded.addField(id('shop:maker'), 'length', int, {value: 0});
        expect(() => loaded.reparent(id('shop:maker'), id('shop:boat'))).toThrow('already has a field "length"');
    });

    it('adds a field with its default, and removes one with what goes through it', () => {
        const {draft: loaded, id, field} = draft();
        const beam = loaded.addField(id('shop:boat'), 'beam', int, {value: 4});
        expect(defaultsOf(loaded, 'shop:boat')).toMatchObject({beam: 4});
        expect(loaded.field(beam)?.was).toBeNull();

        loaded.removeField(field('shop:boat', 'sail'));
        expect(defaultsOf(loaded, 'shop:boat')).toEqual({length: 12, maker: null, beam: 4});
        expect(defaultsOf(loaded, 'shop:yacht'), 'A subtype\'s defaults through it go too.').toEqual({cabins: 2});

        loaded.removeField(field('shop:maker', 'title'));
        expect(loaded.typeNamed('shop:maker')!.now!.uniqueGroups, 'A unique group over it goes.').toEqual([]);

        loaded.history.undo();
        loaded.history.undo();
        expect(defaultsOf(loaded, 'shop:yacht')).toEqual({cabins: 2, 'sail:area': 30});
        expect(loaded.typeNamed('shop:maker')!.now!.uniqueGroups).toHaveLength(1);
    });

    it('retypes a field: its rules and every default through it go, its conversion is asked again', () => {
        const {draft: loaded, field} = draft();
        const length = field('shop:boat', 'length');
        loaded.chooseRetype(length, 'reset');

        loaded.retypeField(length, text, {value: 'twelve'});
        const retyped = loaded.field(length)!;
        expect(retyped.now?.shape).toEqual(text);
        expect(retyped.now?.rules).toEqual([]);
        expect(retyped.choices.retype).toBeNull();
        expect(defaultsOf(loaded, 'shop:boat')).toMatchObject({length: 'twelve'});

        loaded.chooseRetype(length, {kind: 'coconut-cms:number-to-text', parameters: {}});
        expect(loaded.history.undoLabel()).toBe('Choose how length converts');
        loaded.history.undo();
        loaded.history.undo();
        expect(loaded.field(length)!.now?.rules).toHaveLength(1);
        expect(loaded.field(length)!.choices.retype).toBe('reset');
    });

    it('moves a field down its chain: a type no longer holding it loses its defaults through it', () => {
        const {draft: loaded, id, field} = draft();
        const length = field('shop:boat', 'length');

        loaded.moveField(length, id('shop:yacht'), {value: 30});
        expect(loaded.field(length)?.now?.owner).toBe(id('shop:yacht'));
        expect(defaultsOf(loaded, 'shop:boat')).not.toHaveProperty('length');
        expect(defaultsOf(loaded, 'shop:yacht')).toMatchObject({length: 30});

        loaded.history.undo();
        expect(loaded.field(length)?.now?.owner).toBe(id('shop:boat'));
        expect(defaultsOf(loaded, 'shop:boat')).toMatchObject({length: 12});
        expect(() => loaded.moveField(length, id('shop:maker'), {value: 1})).toThrow('aren\'t one chain');
    });

    it('sets and inherits defaults by path, rules and permissions, each undoable', () => {
        const {draft: loaded, id, field} = draft();
        const area = DefaultPath.of(field('shop:boat', 'sail'), field('shop:sail', 'area'));

        loaded.inheritDefault(id('shop:yacht'), area);
        expect(defaultsOf(loaded, 'shop:yacht')).toEqual({cabins: 2});
        loaded.setDefault(id('shop:yacht'), area, 40);
        expect(defaultsOf(loaded, 'shop:yacht')).toEqual({cabins: 2, 'sail:area': 40});

        loaded.setFieldRules(field('shop:yacht', 'cabins'), [{rule: 'coconut-cms:range', config: {max: 9}}]);
        loaded.setFieldPermission(field('shop:yacht', 'cabins'), {read: null, write: 'captain'});
        loaded.setTypePermission(id('shop:yacht'), {read: null, create: 'captain', update: null, delete: null});
        expect(loaded.typeNamed('shop:yacht')!.now!.typePermission?.create).toBe('captain');

        while (loaded.history.canUndo()) {
            loaded.history.undo();
        }
        expect(loaded.typeNamed('shop:yacht')!.now).toBe(loaded.typeNamed('shop:yacht')!.was);
        expect(loaded.field(field('shop:yacht', 'cabins'))!.now).toBe(loaded.field(field('shop:yacht', 'cabins'))!.was);
    });

    it('tells its listeners after every edit, done or undone', () => {
        const {draft: loaded, id} = draft();
        let told = 0;
        loaded.onChange(() => told++);

        loaded.renameType(id('shop:boat'), 'shop:skiff');
        loaded.history.undo();
        expect(told).toBe(2);
    });
});
