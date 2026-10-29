import {Elements} from '../../dom/Elements.ts';
import {ActionIcon} from '../../icons/ActionIcon.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';
import {isReferenceValue} from '../../public/properties/RecordValues.ts';
import {ReferencePicker} from '../ReferencePicker.ts';

/**
 * A Reference: its target's label, an open button, and a button picking
 * another target; clicking the label picks another too. The open button does nothing yet: what opens depends on
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
        const open = ActionIcon.button('open', 'Opening comes later');

        // The box opens the picker too, like Unreal's asset picker: it reads as the value's dropdown.
        const box = Elements.create('button', {classes: ['reference__box'], attributes: {type: 'button'}}, [label]);
        box.disabled = !context.writable;
        const pick = ActionIcon.button('browse', 'Pick another');
        pick.disabled = !context.writable;
        for (const button of [box, pick]) {
            button.addEventListener('click', () => ReferenceEditor.pick(context, box));
        }

        this.widget = Elements.create('span', {classes: ['property__inline', 'reference']}, [box, pick, open]);
    }

    /** Opens the picker under `anchor`; what's picked becomes the value. */
    private static pick(context: PropertyContext, anchor: HTMLElement): void {
        ReferencePicker.open(anchor, context.api, context.catalog, context.value.target ?? '', (picked) => {
            context.slot.set(picked);
            context.rebuild();
        });
    }
}
