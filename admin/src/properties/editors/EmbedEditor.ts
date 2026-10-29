import {Elements} from '../../dom/Elements.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';
import {PropertyPath} from '../../public/properties/PropertyPath.ts';
import {isObjectValue} from '../../public/properties/RecordValues.ts';

/** An Embed, like a struct: its type beside its name, its fields' properties under it. */
export class EmbedEditor implements PropertyEditor {
    public readonly widget: HTMLElement;
    public readonly children: HTMLElement | null;

    public constructor(context: PropertyContext) {
        const value = context.slot.get();
        this.widget = Elements.create('span', {classes: ['muted'], text: context.value.target ?? ''});
        this.children = isObjectValue(value) ? context.fieldsOf(value, PropertyPath.EMBED) : null;
    }
}
