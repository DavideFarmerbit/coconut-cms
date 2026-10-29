import {describe, expect, it} from 'vitest';
import type {ValueDescription} from '../types/TypeDescription.ts';
import type {PropertyEditor, PropertyEditorClass} from './PropertyEditor.ts';
import {PropertyEditors} from './PropertyEditors.ts';

/** A distinct editor class, for telling which one resolved. */
function editor(): PropertyEditorClass {
    return class implements PropertyEditor {
        public readonly widget = {} as HTMLElement;
        public readonly children = null;
    };
}

/** A value description with `changes` over a non-null int scalar. */
function value(changes: Partial<ValueDescription>): ValueDescription {
    return {kind: 'scalar', nullable: false, scalarType: 'int', enumValues: null, target: null, item: null, ...changes};
}

describe('PropertyEditors', () => {
    const byKind = editor();
    const byScalar = editor();
    const byValueType = editor();
    const byField = editor();
    const byParentField = editor();
    const editors = new PropertyEditors();
    editors.register({kind: 'scalar'}, byKind);
    editors.register({kind: 'value_object'}, byKind);
    editors.register({scalar: 'int'}, byScalar);
    editors.register({valueType: 'shop:color'}, byValueType);
    editors.register({type: 'shop:car', field: 'color'}, byField);
    editors.register({type: 'shop:vehicle', field: 'price'}, byParentField);
    const color = value({kind: 'value_object', target: 'shop:color'});

    it('lets the most specific match win', () => {
        expect(editors.resolve(['shop:car', 'shop:vehicle'], 'color', color)).toBe(byField);
        expect(editors.resolve(['shop:truck', 'shop:vehicle'], 'color', color)).toBe(byValueType);
        expect(editors.resolve(['shop:car'], 'doors', value({}))).toBe(byScalar);
        expect(editors.resolve(['shop:car'], 'name', value({scalarType: 'string'}))).toBe(byKind);
    });

    it('matches a field declared on an ancestor for its subtypes', () => {
        expect(editors.resolve(['shop:car', 'shop:vehicle'], 'price', value({}))).toBe(byParentField);
    });

    it('matches an item by what it holds only', () => {
        expect(editors.resolve(['shop:car'], null, color)).toBe(byValueType);
    });

    it('lets a later registration replace an earlier one', () => {
        const replacement = editor();
        editors.register({kind: 'embed'}, byKind);
        editors.register({kind: 'embed'}, replacement);

        expect(editors.resolve([], null, value({kind: 'embed', target: 'shop:chassis'}))).toBe(replacement);
    });
});
