import type {ApiClient} from '../api/ApiClient.ts';
import type {EntityStore} from '../entities/EntityStore.ts';
import type {TypeCatalog} from '../types/TypeCatalog.ts';
import type {ValueDescription} from '../types/TypeDescription.ts';
import type {CollectionItems} from './CollectionItems.ts';
import type {ObjectValue, ReferenceValue} from './RecordValues.ts';
import type {PropertySlot} from './PropertySlot.ts';

/**
 * What a PropertyEditor is built with: the property, its value, and what it
 * needs to show more. Its changes go to the entity's session; the editor
 * never rebuilds the view: the session tells every view what changed.
 */
export interface PropertyContext {
    /** Its path from the entity: "chassis:weight", "wheels.1". */
    readonly path: string;
    /** What it holds. */
    readonly value: ValueDescription;
    /** Its value, to read and change: a change keeps the editor's widget, as typing needs. */
    readonly slot: PropertySlot;
    /** Whether the user may change it. */
    readonly writable: boolean;
    /** A Collection's items, to change one operation at a time; null for any other property. */
    readonly items: CollectionItems | null;
    /** Every type the user may read. */
    readonly catalog: TypeCatalog;
    /** The client every call to the backend goes through. */
    readonly api: ApiClient;
    /** The entities: to find a Reference's targets, read what they're called. */
    readonly entities: EntityStore;

    /**
     * Changes its value and builds its row again, widget and children: what
     * a widget showing more than an input needs, a Reference's label, an
     * owned object's class.
     */
    replace(value: unknown): void;

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
}
