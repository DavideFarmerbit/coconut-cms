import type {ApiClient} from '../api/ApiClient.ts';
import type {TypeCatalog} from '../types/TypeCatalog.ts';
import type {ValueDescription} from '../types/TypeDescription.ts';
import type {ObjectValue, ReferenceValue} from './RecordValues.ts';
import type {PropertySlot} from './PropertySlot.ts';

/** What a PropertyEditor is built with: the property, its value, and what it needs to show more. */
export interface PropertyContext {
    /** Its path from the entity: "chassis:weight", "wheels.1". */
    readonly path: string;
    /** What it holds. */
    readonly value: ValueDescription;
    /** Its value, to read and change. */
    readonly slot: PropertySlot;
    /** Whether the user may change it. */
    readonly writable: boolean;
    /** Every type the user may read. */
    readonly catalog: TypeCatalog;
    /** The client every call to the backend goes through. */
    readonly api: ApiClient;

    /**
     * The properties of `object`'s fields, by the usual rules: what an
     * editor of an Embed or an owned object shows as its children.
     * @param join How `object` joins its fields: ":" for an Embed, "." otherwise.
     */
    fieldsOf(object: ObjectValue, join: string): HTMLElement;

    /** The property of item `index` of the Collection this property holds: what a Collection editor shows per item. */
    itemOf(index: number): HTMLElement;

    /** What the target of `reference` is called; its id when it can't be read. */
    labelOf(reference: ReferenceValue): Promise<string>;

    /** Shows the whole entity again from its working copy: after a change of shape, an item added, a type changed. */
    rebuild(): void;
}
