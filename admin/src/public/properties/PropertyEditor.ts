import type {PropertyContext} from './PropertyContext.ts';

/**
 * What shows one property's value, after Unreal's property customizations:
 * its value widget, beside the property's name, and, for a value holding
 * others, the properties shown under it. A class, built with the
 * property's context; the admin's defaults and every customization alike.
 */
export interface PropertyEditor {
    /** The value widget, beside the name. */
    readonly widget: HTMLElement;

    /** What shows under the name: an Embed's fields, a Collection's items; null for none. */
    readonly children: HTMLElement | null;
}

/** A PropertyEditor class: what admin.properties registers. */
export type PropertyEditorClass = new (context: PropertyContext) => PropertyEditor;
