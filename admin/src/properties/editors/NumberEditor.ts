import {Elements} from '../../dom/Elements.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';

/**
 * An Int or a Float in a number box; a Decimal in a text box, since it's
 * stored as text, "12.50". Emptied, a nullable one holds null; text that
 * isn't a number is left unsaved, marked invalid.
 */
export class NumberEditor implements PropertyEditor {
    public readonly widget: HTMLElement;
    public readonly children = null;

    public constructor(context: PropertyContext) {
        const type = context.value.scalarType;
        const input = Elements.create('input', {classes: ['field__input', 'field__input--number']});
        if (type === 'decimal') {
            input.inputMode = 'decimal';
        } else {
            input.type = 'number';
            input.step = type === 'int' ? '1' : 'any';
        }

        const current = context.slot.get();
        input.value = current === null || current === undefined ? '' : String(current);
        input.disabled = !context.writable;
        input.addEventListener('input', () => {
            const text = input.value.trim();
            const parsed = NumberEditor.parse(text, type);
            input.classList.toggle('is-invalid', parsed === undefined && !(text === '' && context.value.nullable));
            if (text === '' && context.value.nullable) {
                context.slot.set(null);
            } else if (parsed !== undefined) {
                context.slot.set(parsed);
            }
        });
        this.widget = input;
    }

    /** `text` as a value of `type`: a number, or a Decimal's text; undefined when it isn't one. */
    private static parse(text: string, type: string | null): number | string | undefined {
        if (type === 'decimal') {
            return /^-?\d+(\.\d+)?$/.test(text) ? text : undefined;
        }

        if (text === '' || Number.isNaN(Number(text))) {
            return undefined;
        }

        const number = Number(text);

        return type === 'int' && !Number.isInteger(number) ? undefined : number;
    }
}
