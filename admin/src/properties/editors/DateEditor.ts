import {Elements} from '../../dom/Elements.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';

/**
 * A Date in a date box; a DateTime in a date-and-time box, in UTC, as it's
 * stored, "2024-05-01 10:30:00", and labelled so.
 */
export class DateEditor implements PropertyEditor {
    public readonly widget: HTMLElement;
    public readonly children = null;

    public constructor(context: PropertyContext) {
        const withTime = context.value.scalarType === 'date_time';
        const input = Elements.create('input', {classes: ['field__input'], attributes: {type: withTime ? 'datetime-local' : 'date'}});
        if (withTime) {
            input.step = '1';
        }

        const current = context.slot.get();
        input.value = typeof current === 'string' ? current.replace(' ', 'T') : '';
        input.disabled = !context.writable;
        input.addEventListener('input', () => {
            if (input.value === '') {
                if (context.value.nullable) {
                    context.slot.set(null);
                }

                return;
            }

            // A browser may leave the seconds out when they're 0.
            context.slot.set(withTime ? `${input.value.replace('T', ' ')}${input.value.length === 16 ? ':00' : ''}` : input.value);
        });

        this.widget = withTime
            ? Elements.create('span', {classes: ['property__inline']}, [input, Elements.create('span', {classes: ['muted'], text: 'UTC'})])
            : input;
    }
}
