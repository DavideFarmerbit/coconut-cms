import {Elements} from '../../dom/Elements.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';
import {PropertyPath} from '../../public/properties/PropertyPath.ts';
import {isObjectValue} from '../../public/properties/RecordValues.ts';
import {NewValues} from '../NewValues.ts';

/**
 * An owned object, as an edit-inline instanced subobject: a dropdown of its
 * type, the field's target and every type extending it, with "None" when it
 * may be empty, and its properties under it. Picking another type replaces
 * the object with a new one of that type, from its defaults.
 */
export class OwnedEditor implements PropertyEditor {
    /** The dropdown's value for an empty slot: no type identifier is empty. */
    private static readonly NONE = '';

    public readonly widget: HTMLElement;
    public readonly children: HTMLElement | null;

    public constructor(context: PropertyContext) {
        const value = context.slot.get();
        const types = context.catalog.withSubtypes(context.value.target ?? '');
        const select = Elements.create('select', {classes: ['field__input']}, [
            ...(context.value.nullable ? [Elements.create('option', {text: 'None', attributes: {value: OwnedEditor.NONE}})] : []),
            ...types.map((type) => Elements.create('option', {text: type.identifier, attributes: {value: type.identifier}})),
        ]);
        select.value = isObjectValue(value) ? value.type : OwnedEditor.NONE;
        select.disabled = !context.writable;
        select.addEventListener('change', () => {
            context.slot.set(select.value === OwnedEditor.NONE ? null : NewValues.object(context.catalog, select.value));
            context.rebuild();
        });

        this.widget = select;
        this.children = isObjectValue(value) ? context.fieldsOf(value, PropertyPath.TRAVERSE) : null;
    }
}
