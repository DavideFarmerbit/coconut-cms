import {ChoicePicker} from '../../dom/ChoicePicker.ts';
import {Elements} from '../../dom/Elements.ts';
import {LocalChoices} from '../../dom/LocalChoices.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';
import {PropertyPath} from '../../public/properties/PropertyPath.ts';
import {isObjectValue} from '../../public/properties/RecordValues.ts';
import {NewValues} from '../NewValues.ts';

/**
 * An owned object, as an edit-inline instanced subobject: a class picker of
 * its type, the field's target and every type extending it, with "None"
 * when it may be empty, and its properties under it. Picking another type
 * replaces the object with a new one of that type, from its defaults.
 */
export class OwnedEditor implements PropertyEditor {
    /** The key of "None": no type identifier is empty. */
    private static readonly NONE = '';

    public readonly widget: HTMLElement;
    public readonly children: HTMLElement | null;

    public constructor(context: PropertyContext) {
        const value = context.slot.get();
        const current = isObjectValue(value) ? value.type : null;
        const types = context.catalog.withSubtypes(context.value.target ?? '').map((type) => ({key: type.identifier, label: type.identifier, value: type.identifier}));

        const button = Elements.create('button', {classes: ['field__input', 'field__input--dropdown'], text: current ?? 'None', attributes: {type: 'button'}});
        button.disabled = !context.writable;
        button.addEventListener('click', () => ChoicePicker.open<string | null>(button, new LocalChoices<string | null>(types), {
            placeholder: 'Search classes',
            pinned: context.value.nullable ? [{key: OwnedEditor.NONE, label: 'None', value: null}] : [],
            current: current ?? OwnedEditor.NONE,
            picked: (type) => {
                if (type !== current) {
                    context.replace(type === null ? null : NewValues.object(context.catalog, type));
                }
            },
        }));

        // A class picker, set apart from a value's field: the object's class, chosen.
        this.widget = Elements.create('span', {classes: ['class-picker', ...(current === null ? ['is-empty'] : [])]}, [
            Elements.create('span', {classes: ['class-picker__label'], text: 'Class'}),
            button,
        ]);
        this.children = isObjectValue(value) ? context.fieldsOf(value, PropertyPath.TRAVERSE) : null;
    }
}
