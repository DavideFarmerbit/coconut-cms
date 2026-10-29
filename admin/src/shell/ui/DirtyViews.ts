/** Told that which views have unsaved changes changed. */
export type DirtyChanged = () => void;

/**
 * Which views have unsaved changes, by view key, as each view tells through
 * its context's markDirty(). Not part of the layout: unsaved work doesn't
 * survive a reload, so neither does its mark.
 */
export class DirtyViews {
    private readonly keys = new Set<string>();
    private readonly listeners: DirtyChanged[] = [];

    /** Notes whether the view under `key` has unsaved changes. */
    public set(key: string, dirty: boolean): void {
        if (dirty === this.keys.has(key)) {
            return;
        }

        if (dirty) {
            this.keys.add(key);
        } else {
            this.keys.delete(key);
        }

        for (const listener of this.listeners) {
            listener();
        }
    }

    /** Whether the view under `key` has unsaved changes. */
    public has(key: string): boolean {
        return this.keys.has(key);
    }

    /** Whether any view has. */
    public get any(): boolean {
        return this.keys.size > 0;
    }

    /** Tells `listener` of every change. */
    public onChange(listener: DirtyChanged): void {
        this.listeners.push(listener);
    }
}
