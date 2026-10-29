import {describe, expect, it} from 'vitest';
import {TypeCatalog} from './TypeCatalog.ts';
import type {TypeDescription} from './TypeDescription.ts';

/** A type named `identifier`, extending `parent`, with no fields. */
function type(identifier: string, parent: string | null = null): TypeDescription {
    return {identifier, parent, editorExtensible: false, may: {read: true, create: true, update: true, delete: true}, fields: {}};
}

describe('TypeCatalog', () => {
    const catalog = TypeCatalog.of([
        type('shop:car', 'shop:vehicle'),
        type('shop:vehicle'),
        type('shop:sports_car', 'shop:car'),
        type('app:log'),
        type('shop:truck', 'shop:vehicle'),
    ]);

    it('groups types by namespace, both sorted', () => {
        const groups = catalog.byNamespace();

        expect([...groups.keys()]).toEqual(['app', 'shop']);
        expect(groups.get('shop')?.map((each) => each.identifier)).toEqual(['shop:car', 'shop:sports_car', 'shop:truck', 'shop:vehicle']);
    });

    it('walks a type\'s ancestors, its parent first', () => {
        expect(catalog.ancestorsOf('shop:sports_car').map((each) => each.identifier)).toEqual(['shop:car', 'shop:vehicle']);
        expect(catalog.ancestorsOf('shop:vehicle')).toEqual([]);
    });

    it('finds a type\'s direct subtypes', () => {
        expect(catalog.subtypesOf('shop:vehicle').map((each) => each.identifier)).toEqual(['shop:car', 'shop:truck']);
    });

    it('splits an identifier into its namespace and key', () => {
        expect(TypeCatalog.namespaceOf('default:demo_car')).toBe('default');
        expect(TypeCatalog.keyOf('default:demo_car')).toBe('demo_car');
    });
});
