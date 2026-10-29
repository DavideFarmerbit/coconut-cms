import {PropertyPath} from '../public/properties/PropertyPath.ts';
import type {PropertySlot} from '../public/properties/PropertySlot.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';
import {ReadTree} from './ReadTree.ts';
import {WriteForm} from './WriteForm.ts';

/** Told that the draft's changes changed: a property set, reset or reverted, a save accepted. */
export type DraftChanged = () => void;

/**
 * An entity being edited: what was saved, a working copy the properties
 * change, and which paths changed. A change at a path covers everything
 * under it: a Collection replaced whole carries its items' edits. Nothing
 * is sent until changes() is asked for, so each path's value is the latest.
 */
export class EntityDraft {
    private saved: Record<string, unknown>;
    private working: Record<string, unknown>;
    private readonly changed = new Map<string, ValueDescription>();
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

    /** Whether anything changed since the last save. */
    public get isDirty(): boolean {
        return this.changed.size > 0;
    }

    /** Whether the property at `path`, or anything under it, changed. */
    public hasChangedAt(path: string): boolean {
        return [...this.changed.keys()].some((changed) => changed === path || PropertyPath.isUnder(changed, path) || PropertyPath.isUnder(path, changed));
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

    /** What was saved at `path`. */
    public savedAt(path: string): unknown {
        return ReadTree.at(this.saved, path);
    }

    /** Puts back what was saved at `path`, dropping its changes and those under it. */
    public resetAt(path: string): void {
        ReadTree.put(this.working, path, structuredClone(this.savedAt(path)));
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

    /** Every change by path, in the form an update takes. */
    public changes(catalog: TypeCatalog): Record<string, unknown> {
        const form = new WriteForm(catalog);
        const changes: Record<string, unknown> = {};
        for (const [path, value] of this.changed) {
            changes[path] = form.of(value, ReadTree.at(this.working, path));
        }

        return changes;
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
