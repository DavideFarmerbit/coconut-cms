import type {CollectionItems} from '../public/properties/CollectionItems.ts';
import type {PropertySlot} from '../public/properties/PropertySlot.ts';
import type {PropertyStatus} from '../public/properties/PropertyStatus.ts';
import {PropertyPath} from '../public/properties/PropertyPath.ts';
import {isObjectValue} from '../public/properties/RecordValues.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';
import type {DraftState} from './DraftState.ts';
import type {EntityDraft} from './EntityDraft.ts';

/** Told after an operation on a Collection's items: what it's called, and the draft as it was before it. */
export type ItemsEdited = (label: string, before: DraftState) => void;

/** A Collection property's items in an entity draft: each operation restructures the draft's list, keeping where each item came from. */
export class DraftCollectionItems implements CollectionItems {
    /**
     * @param slot The Collection's slot, to read its list, and to start one when it holds none.
     * @param edited Told after every operation: the property was edited, as an undoable edit.
     */
    public constructor(
        private readonly draft: EntityDraft,
        private readonly slot: PropertySlot,
        private readonly path: string,
        private readonly value: ValueDescription,
        private readonly edited: ItemsEdited,
    ) {
    }

    public get values(): readonly unknown[] {
        const items = this.slot.get();

        return Array.isArray(items) ? items : [];
    }

    public get removedCount(): number {
        const items = this.slot.get();

        return Array.isArray(items) ? this.draft.removedCountAt(this.path, items) : 0;
    }

    public insert(item: unknown, index?: number): void {
        this.restructure(`Add an item to ${this.path}`, (items, origins) => {
            const at = index ?? items.length;
            items.splice(at, 0, item);
            origins.splice(at, 0, null);
        });
    }

    public remove(index: number): void {
        this.restructure(`Remove an item from ${this.path}`, (items, origins) => {
            items.splice(index, 1);
            origins.splice(index, 1);
        });
    }

    public move(from: number, to: number): void {
        this.restructure(`Move an item in ${this.path}`, (items, origins) => {
            items.splice(to, 0, ...items.splice(from, 1));
            origins.splice(to, 0, ...origins.splice(from, 1));
        });
    }

    public duplicate(index: number): void {
        this.restructure(`Duplicate an item in ${this.path}`, (items, origins) => {
            items.splice(index + 1, 0, DraftCollectionItems.copy(items[index]));
            origins.splice(index + 1, 0, null);
        });
    }

    public clear(): void {
        this.restructure(`Remove every item from ${this.path}`, (items, origins) => {
            items.length = 0;
            origins.length = 0;
        });
    }

    public statusOf(index: number): PropertyStatus {
        return this.draft.statusAt(PropertyPath.child(this.path, PropertyPath.TRAVERSE, index));
    }

    /** Runs `change`, called `label`, on the list and its items' origins; a property holding no list gets an empty one first. */
    private restructure(label: string, change: (items: unknown[], origins: (number | null)[]) => void): void {
        const before = this.draft.snapshot();
        let items = this.slot.get();
        if (!Array.isArray(items)) {
            this.slot.set([]);
            items = this.slot.get();
        }

        this.draft.restructure(items as unknown[], this.path, this.value, change);
        this.edited(label, before);
    }

    /** A copy of `item`: every owned object in it is a new one, with no id. */
    private static copy(item: unknown): unknown {
        if (Array.isArray(item)) {
            return item.map((each) => DraftCollectionItems.copy(each));
        }

        if (isObjectValue(item)) {
            return {type: item.type, id: null, values: Object.fromEntries(Object.entries(item.values).map(([name, each]) => [name, DraftCollectionItems.copy(each)]))};
        }

        return structuredClone(item);
    }
}
