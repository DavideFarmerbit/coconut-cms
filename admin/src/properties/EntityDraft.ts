import {PropertyPath} from '../public/properties/PropertyPath.ts';
import type {PropertySlot} from '../public/properties/PropertySlot.ts';
import type {PropertyStatus} from '../public/properties/PropertyStatus.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';
import {ReadTree} from './ReadTree.ts';
import {Values} from './Values.ts';
import {WriteForm} from './WriteForm.ts';

/** Told that the draft's changes changed: a property set, reset or reverted, a save accepted. */
export type DraftChanged = () => void;

/** Changes a Collection's items, and alongside them where each came from. */
export type Restructure = (items: unknown[], origins: (number | null)[]) => void;

/** What a property's path reaches now, and what it stood for when saved. */
interface Counterpart {
    /** The value at the path in the working copy. */
    readonly working: unknown;
    /** Whether something saved stands for it; not for a new item, or anything in one. */
    readonly found: boolean;
    /** What stands for it in the saved values. */
    readonly saved: unknown;
    /** When the path ends at an item that moved, its index when saved. */
    readonly movedFrom: number | null;
}

/**
 * An entity being edited: what was saved, a working copy the properties
 * change, and which paths were changed, what a save sends. A change at a path
 * covers everything under it. A Collection's items keep where each came from
 * when restructured, so a property is compared with what stood for it when
 * saved: a moved item isn't changed, and a change undone is clean again.
 */
export class EntityDraft {
    private saved: Record<string, unknown>;
    private working: Record<string, unknown>;
    private readonly changed = new Map<string, ValueDescription>();
    /** Each restructured Collection's items' saved indexes, null for a new item, by the working copy's list. */
    private readonly origins = new WeakMap<unknown[], (number | null)[]>();
    private readonly listeners: DraftChanged[] = [];

    /** @param values The entity's values, as a read gives them. */
    public constructor(values: Readonly<Record<string, unknown>>) {
        this.saved = structuredClone(values) as Record<string, unknown>;
        this.working = structuredClone(values) as Record<string, unknown>;
    }

    /** The working copy's values. */
    public get values(): Record<string, unknown> {
        return this.working;
    }

    /** Whether anything differs from what was saved. */
    public get isDirty(): boolean {
        return this.differing().length > 0;
    }

    /** Where the property at `path` stands against what was saved. */
    public statusAt(path: string): PropertyStatus {
        const counterpart = this.counterpart(path);
        if (!counterpart.found) {
            return {isNew: true, movedFrom: null, isChanged: true};
        }

        return {isNew: false, movedFrom: counterpart.movedFrom, isChanged: !Values.equal(counterpart.working, counterpart.saved)};
    }

    /** Tells `listener` of every change of the changes. */
    public onChange(listener: DraftChanged): void {
        this.listeners.push(listener);
    }

    /** The slot of `holder[key]`, the property at `path` holding what `value` describes. */
    public slot(holder: Record<string, unknown> | unknown[], key: string | number, path: string, value: ValueDescription): PropertySlot {
        return {
            get: () => (holder as Record<string | number, unknown>)[key],
            set: (next: unknown) => {
                (holder as Record<string | number, unknown>)[key] = next;
                this.mark(path, value);
            },
        };
    }

    /**
     * Changes `items`, the Collection at `path` holding what `value`
     * describes, in place by `change`, which keeps each item's origin
     * alongside: its index when saved, null for a new one.
     */
    public restructure(items: unknown[], path: string, value: ValueDescription, change: Restructure): void {
        const origins = [...this.originsOf(items, this.counterpart(path).saved)];
        change(items, origins);
        this.origins.set(items, origins);
        this.mark(path, value);
    }

    /** How many items saved in the Collection at `path`, holding `items` now, are no longer there. */
    public removedCountAt(path: string, items: unknown[]): number {
        const saved = this.counterpart(path).saved;

        return Array.isArray(saved) ? saved.length - this.originsOf(items, saved).filter((origin) => origin !== null).length : 0;
    }

    /** Puts back what was saved for `path`, dropping its changes and those under it; nothing for a new item. */
    public resetAt(path: string): void {
        const counterpart = this.counterpart(path);
        if (!counterpart.found) {
            return;
        }

        ReadTree.put(this.working, path, structuredClone(counterpart.saved));
        for (const changed of [...this.changed.keys()]) {
            if (changed === path || PropertyPath.isUnder(changed, path)) {
                this.changed.delete(changed);
            }
        }
        this.notify();
    }

    /** Drops every change. */
    public revert(): void {
        this.working = structuredClone(this.saved);
        this.changed.clear();
        this.notify();
    }

    /** Makes `values`, what a save gave back, the saved values and the working copy. */
    public accept(values: Readonly<Record<string, unknown>>): void {
        this.saved = structuredClone(values) as Record<string, unknown>;
        this.working = structuredClone(values) as Record<string, unknown>;
        this.changed.clear();
        this.notify();
    }

    /** Every change by path, in the form an update takes; a path changed back isn't sent. */
    public changes(catalog: TypeCatalog): Record<string, unknown> {
        const form = new WriteForm(catalog);
        const changes: Record<string, unknown> = {};
        for (const [path, value] of this.differing()) {
            changes[path] = form.of(value, ReadTree.at(this.working, path));
        }

        return changes;
    }

    /** The changed paths whose value differs from what was saved there. */
    private differing(): [string, ValueDescription][] {
        // A changed path never lies in a restructured Collection: that is changed itself, and covers it. So the saved value is the one at the same path.
        return [...this.changed].filter(([path]) => !Values.equal(ReadTree.at(this.working, path), ReadTree.at(this.saved, path)));
    }

    /** Follows `path` through the working copy and, alongside, through what was saved, items by their origin. */
    private counterpart(path: string): Counterpart {
        let working: unknown = this.working;
        let saved: unknown = this.saved;
        let movedFrom: number | null = null;
        for (const [depth, segment] of PropertyPath.segments(path).entries()) {
            movedFrom = null;
            if (Array.isArray(working) && typeof segment === 'number') {
                const origin = this.originsOf(working, saved)[segment] ?? null;
                if (origin === null) {
                    return {working: working[segment], found: false, saved: undefined, movedFrom: null};
                }
                movedFrom = origin === segment ? null : origin;
                working = working[segment];
                saved = Array.isArray(saved) ? saved[origin] : undefined;
            } else {
                working = ReadTree.step(working, segment, depth === 0);
                saved = saved === undefined ? undefined : ReadTree.step(saved, segment, depth === 0);
            }
        }

        return {working, found: true, saved, movedFrom};
    }

    /** Each of `items`' saved index, `saved` being the list saved: as kept when restructured, else each its own index while there was one. */
    private originsOf(items: unknown[], saved: unknown): readonly (number | null)[] {
        const savedCount = Array.isArray(saved) ? saved.length : 0;

        return this.origins.get(items) ?? items.map((_item, index) => index < savedCount ? index : null);
    }

    /** Notes that the property at `path` changed; a change already covering it is enough. */
    private mark(path: string, value: ValueDescription): void {
        if (![...this.changed.keys()].some((changed) => PropertyPath.isUnder(path, changed))) {
            for (const changed of [...this.changed.keys()]) {
                if (PropertyPath.isUnder(changed, path)) {
                    this.changed.delete(changed);
                }
            }
            this.changed.set(path, value);
        }
        this.notify();
    }

    private notify(): void {
        for (const listener of this.listeners) {
            listener();
        }
    }
}
