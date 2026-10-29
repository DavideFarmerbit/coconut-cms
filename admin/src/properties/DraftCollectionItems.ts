import type {CollectionItems} from '../public/properties/CollectionItems.ts';
import type {PropertySlot} from '../public/properties/PropertySlot.ts';
import type {PropertyStatus} from '../public/properties/PropertyStatus.ts';
import {PropertyPath} from '../public/properties/PropertyPath.ts';
import {isObjectValue} from '../public/properties/RecordValues.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';
import type {EntityDraft} from './EntityDraft.ts';

/** A Collection property's items in an entity draft: each operation restructures the draft's list, keeping where each item came from. */
export class DraftCollectionItems implements CollectionItems {
    /**
     * @param slot The Collection's slot, to read its list, and to start one when it holds none.
     * @param edited Told after every operation: the property was edited.
     */
    public constructor(
        private readonly draft: EntityDraft,
        private readonly slot: PropertySlot,
        private readonly path: string,
        private readonly value: ValueDescription,
        private readonly edited: () => void,
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
        this.restructure((items, origins) => {
            const at = index ?? items.length;
            items.splice(at, 0, item);
            origins.splice(at, 0, null);
        });
    }

    public remove(index: number): void {
        this.restructure((items, origins) => {
            items.splice(index, 1);
            origins.splice(index, 1);
        });
    }

    public move(from: number, to: number): void {
        this.restructure((items, origins) => {
            items.splice(to, 0, ...items.splice(from, 1));
            origins.splice(to, 0, ...origins.splice(from, 1));
        });
    }

    public duplicate(index: number): void {
        this.restructure((items, origins) => {
            items.splice(index + 1, 0, DraftCollectionItems.copy(items[index]));
            origins.splice(index + 1, 0, null);
        });
    }

    public clear(): void {
        this.restructure((items, origins) => {
            items.length = 0;
            origins.length = 0;
        });
    }

    public statusOf(index: number): PropertyStatus {
        return this.draft.statusAt(PropertyPath.child(this.path, PropertyPath.TRAVERSE, index));
    }

    /** Runs `change` on the list and its items' origins; a property holding no list gets an empty one first. */
    private restructure(change: (items: unknown[], origins: (number | null)[]) => void): void {
        let items = this.slot.get();
        if (!Array.isArray(items)) {
            this.slot.set([]);
            items = this.slot.get();
        }

        this.draft.restructure(items as unknown[], this.path, this.value, change);
        this.edited();
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
