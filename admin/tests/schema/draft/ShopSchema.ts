import type {SchemaDefinitionList, TypeDefinition} from '../../../src/schema/SchemaDefinitions.ts';
import type {FieldId, TypeId} from '../../../src/schema/draft/DraftIds.ts';
import {SchemaDraft} from '../../../src/schema/draft/SchemaDraft.ts';

/** A type as schema.get gives it, every part empty unless `changes` sets it. */
export function type(identifier: string, changes: Partial<TypeDefinition>): TypeDefinition {
    return {identifier, source: 'editor', parent: null, editorExtensible: true, fields: {}, defaults: {}, fieldRules: {}, fieldPermissions: {}, prototypeRules: [], typePermission: null, uniqueGroups: [], ...changes};
}

export const int = {kind: 'scalar', nullable: false, scalarType: 'int'} as const;
export const text = {kind: 'scalar', nullable: false, scalarType: 'string'} as const;

/**
 * A native vehicle allowing editor subtypes and a native engine allowing
 * none; the editor's boat extends the vehicle, holds a sail and names a
 * maker; its yacht sets the sail's area again, and checks its cabins.
 */
export const shopSchema: SchemaDefinitionList = {
    revision: 4,
    types: [
        type('shop:vehicle', {source: 'native', fields: {name: text}, defaults: {name: ''}}),
        type('shop:engine', {source: 'native', editorExtensible: false, fields: {power: int}, defaults: {power: 90}}),
        type('shop:sail', {fields: {area: int}, defaults: {area: 10}}),
        type('shop:maker', {fields: {title: text}, defaults: {title: ''}, uniqueGroups: [['title']]}),
        type('shop:boat', {
            parent: 'shop:vehicle',
            fields: {length: int, sail: {kind: 'embed', nullable: false, target: 'shop:sail'}, maker: {kind: 'reference', nullable: true, target: 'shop:maker'}},
            defaults: {length: 12, 'sail:area': 20, maker: null},
            fieldRules: {length: [{rule: 'coconut-cms:range', config: {min: 1}}]},
        }),
        type('shop:yacht', {parent: 'shop:boat', fields: {cabins: int}, defaults: {cabins: 2, 'sail:area': 30}, prototypeRules: [{rule: 'shop:cabins-fit', config: {field: 'cabins'}}]}),
    ],
};

/** A fresh draft of the shop, or of `schema`, with what tests reach by name. */
export function shopDraft(schema: SchemaDefinitionList = shopSchema): {draft: SchemaDraft; id: (identifier: string) => TypeId; field: (type: string, name: string) => FieldId} {
    const loaded = SchemaDraft.load(schema);

    return {
        draft: loaded,
        id: (identifier) => loaded.typeNamed(identifier)!.id,
        field: (owner, name) => loaded.fieldNamed(loaded.typeNamed(owner)!.id, name)!.id,
    };
}
