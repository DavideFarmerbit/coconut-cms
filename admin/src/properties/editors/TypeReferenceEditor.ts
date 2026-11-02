import {Elements} from '../../dom/Elements.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';

/**
 * A type reference as a dropdown of the types it may name: its base and
 * every type extending it, as the catalog has them, each by identifier; a
 * nullable one starts with an empty choice. A type it holds that the
 * catalog doesn't show is kept as a choice, so nothing changes by itself.
 */
export class TypeReferenceEditor implements PropertyEditor {
    /** The empty choice's value: no identifier is empty. */
    private static readonly NONE = '';

    public readonly widget: HTMLElement;
    public readonly children = null;

    public constructor(context: PropertyContext) {
        const current = context.slot.get();
        const types = context.catalog.withSubtypes(context.value.target ?? '').map((type) => type.identifier);
        const held = typeof current === 'string' && !types.includes(current) ? [current] : [];
        const select = Elements.create('select', {classes: ['field__input']}, [
            ...(context.value.nullable ? [Elements.create('option', {text: 'None', attributes: {value: TypeReferenceEditor.NONE}})] : []),
            ...[...held, ...types].map((identifier) => Elements.create('option', {text: identifier, attributes: {value: identifier}})),
        ]);
        select.value = typeof current === 'string' ? current : TypeReferenceEditor.NONE;
        select.disabled = !context.writable;
        select.addEventListener('change', () => {
            context.slot.set(select.value === TypeReferenceEditor.NONE ? null : select.value);
        });
        this.widget = select;
    }
}
