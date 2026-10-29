import {Elements} from '../../dom/Elements.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';

/**
 * A String in a text box, a Text in a growing text area, a value object as
 * its stored text. Emptied, a nullable one holds null.
 */
export class TextEditor implements PropertyEditor {
    public readonly widget: HTMLElement;
    public readonly children = null;

    public constructor(context: PropertyContext) {
        const multiline = context.value.kind === 'scalar' && context.value.scalarType === 'text';
        const input = multiline
            ? Elements.create('textarea', {classes: ['field__input', 'field__input--area'], attributes: {rows: '3'}})
            : Elements.create('input', {classes: ['field__input']});
        const current = context.slot.get();
        input.value = typeof current === 'string' ? current : '';
        input.disabled = !context.writable;
        if (context.value.nullable) {
            input.placeholder = 'Empty';
        }

        input.addEventListener('input', () => {
            context.slot.set(input.value === '' && context.value.nullable ? null : input.value);
        });
        this.widget = input;
    }
}
