import {Elements} from '../../dom/Elements.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';

/** A Bool as a checkbox. */
export class BoolEditor implements PropertyEditor {
    public readonly widget: HTMLElement;
    public readonly children = null;

    public constructor(context: PropertyContext) {
        const input = Elements.create('input', {attributes: {type: 'checkbox'}});
        input.checked = context.slot.get() === true;
        input.disabled = !context.writable;
        input.addEventListener('change', () => context.slot.set(input.checked));
        this.widget = Elements.create('label', {classes: ['check']}, [input]);
    }
}
