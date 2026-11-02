import type {Edit} from './Edit.ts';

/** Told that an EditHistory changed: an edit done, undone, redone, or the history cleared. */
export type HistoryListener = () => void;

/**
 * The edits made in one edit context, undoable and redoable in turn: an
 * entity editor's tab, the schema editor, a plugin's editor. Each context
 * holds its own; the view showing it gives it as its PanelView's `history`,
 * so the shell sends it Cmd or Ctrl and Z. A new edit clears what could be
 * redone.
 */
export class EditHistory {
    private readonly done: Edit[] = [];
    private readonly undone: Edit[] = [];
    private readonly listeners: HistoryListener[] = [];

    /** Applies `edit` and records it: the next undo() reverts it. */
    public do(edit: Edit): void {
        edit.apply();
        this.done.push(edit);
        this.undone.length = 0;
        this.changed();
    }

    /** Whether there's an edit to undo. */
    public canUndo(): boolean {
        return this.done.length > 0;
    }

    /** Whether there's an edit to redo. */
    public canRedo(): boolean {
        return this.undone.length > 0;
    }

    /** What undo() would revert, as a menu names it; null when nothing. */
    public undoLabel(): string | null {
        return this.done[this.done.length - 1]?.label ?? null;
    }

    /** What redo() would apply again, as a menu names it; null when nothing. */
    public redoLabel(): string | null {
        return this.undone[this.undone.length - 1]?.label ?? null;
    }

    /** Reverts the last edit done. Nothing when there's none. */
    public undo(): void {
        const edit = this.done.pop();
        if (edit === undefined) {
            return;
        }

        edit.revert();
        this.undone.push(edit);
        this.changed();
    }

    /** Applies again the last edit undone. Nothing when there's none. */
    public redo(): void {
        const edit = this.undone.pop();
        if (edit === undefined) {
            return;
        }

        edit.apply();
        this.done.push(edit);
        this.changed();
    }

    /** Forgets every edit: after a save, or a reload, nothing before it can be undone. */
    public clear(): void {
        if (this.done.length === 0 && this.undone.length === 0) {
            return;
        }

        this.done.length = 0;
        this.undone.length = 0;
        this.changed();
    }

    /** Tells `listener` of every change to it. */
    public onChange(listener: HistoryListener): void {
        this.listeners.push(listener);
    }

    /** Stops telling `listener`. */
    public offChange(listener: HistoryListener): void {
        const index = this.listeners.indexOf(listener);
        if (index >= 0) {
            this.listeners.splice(index, 1);
        }
    }

    private changed(): void {
        for (const listener of [...this.listeners]) {
            listener();
        }
    }
}
