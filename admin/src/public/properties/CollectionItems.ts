import type {PropertyStatus} from './PropertyStatus.ts';

/**
 * A Collection property's items, changed one operation at a time so each
 * item keeps its story: where it came from, whether it's new, how many were
 * removed. A Collection editor changes its items through it, not by setting
 * its slot, so the entity's view marks only what really changed: a moved item
 * isn't changed, and items moved back are clean again.
 */
export interface CollectionItems {
    /** The items now. */
    readonly values: readonly unknown[];

    /** How many items saved are no longer there. */
    readonly removedCount: number;

    /** Puts `item`, new, at `index`; at the end when it's left out. */
    insert(item: unknown, index?: number): void;

    /** Takes item `index` out. */
    remove(index: number): void;

    /** Moves item `from` to `to`. */
    move(from: number, to: number): void;

    /** Puts a copy of item `index` right after it, as a new item: a copied owned object gets no id. */
    duplicate(index: number): void;

    /** Takes every item out. */
    clear(): void;

    /** Where item `index` stands against what was saved. */
    statusOf(index: number): PropertyStatus;
}
