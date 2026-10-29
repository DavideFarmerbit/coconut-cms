import {Elements} from '../../dom/Elements.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';

/** An Enum as a dropdown of its values; a nullable one starts with an empty choice. */
export class EnumEditor implements PropertyEditor {
    /** The empty choice's value: no enum value is a JSON "null". */
    private static readonly NONE = '\u0000none';

    public readonly widget: HTMLElement;
    public readonly children = null;

    public constructor(context: PropertyContext) {
        const values = context.value.enumValues ?? [];
        const select = Elements.create('select', {classes: ['field__input']}, [
            ...(context.value.nullable ? [Elements.create('option', {text: 'None', attributes: {value: EnumEditor.NONE}})] : []),
            ...values.map((value) => Elements.create('option', {text: String(value), attributes: {value: JSON.stringify(value)}})),
        ]);
        const current = context.slot.get();
        select.value = current === null || current === undefined ? EnumEditor.NONE : JSON.stringify(current);
        select.disabled = !context.writable;
        select.addEventListener('change', () => {
            context.slot.set(select.value === EnumEditor.NONE ? null : JSON.parse(select.value));
        });
        this.widget = select;
    }
}
