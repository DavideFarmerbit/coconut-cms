import {Elements} from '../../dom/Elements.ts';
import {Tooltips} from '../../dom/Tooltips.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';
import {isReferenceValue} from '../../public/properties/RecordValues.ts';
import {ReferencePicker} from '../ReferencePicker.ts';

/**
 * A Reference: its target's label, an open button, and a button picking
 * another target. The open button does nothing yet: what opens depends on
 * the target's type, and a registry of editors by type comes later.
 */
export class ReferenceEditor implements PropertyEditor {
    public readonly widget: HTMLElement;
    public readonly children = null;

    public constructor(context: PropertyContext) {
        const value = context.slot.get();
        const label = Elements.create('span', {classes: ['reference__label'], text: isReferenceValue(value) ? '…' : 'None'});
        if (isReferenceValue(value)) {
            label.classList.add('is-set');
            void context.labelOf(value).then((text) => {
                label.textContent = text;
            });
        }

        // A dead button for now, kept so the row's layout is final.
        const open = Elements.create('button', {
            classes: ['icon-button'],
            text: '↗',
            attributes: {type: 'button', 'aria-label': 'Open', ...Tooltips.attributes('Opening comes later', 'top')},
        });

        const pick = Elements.create('button', {classes: ['button', 'button--small'], text: 'Pick…', attributes: {type: 'button'}});
        pick.disabled = !context.writable;
        pick.addEventListener('click', () => ReferencePicker.open(pick, context.api, context.catalog, context.value.target ?? '', (picked) => {
            context.slot.set(picked);
            context.rebuild();
        }));

        this.widget = Elements.create('span', {classes: ['property__inline', 'reference']}, [label, open, pick]);
    }
}
