import type {Edit} from './Edit.ts';

/** Told that an EditHistory changed: an edit done, undone, redone, or the history cleared. */
export type HistoryListener = () => void;

/**
 * The edits made to one thing being edited, undoable and redoable in turn,
 * whichever view made them: an entity's session, the schema draft, a
 * plugin's own model. What's edited holds it, never a view, so every view
 * of it undoes the same edits; a view gives the history of what it's
 * editing where the focus is as its PanelView's `history`, so the shell
 * sends it Cmd or Ctrl and Z. A new edit clears what could be redone. The
 * last edit may absorb the next, until sealed: what's typed in one field,
 * until the focus leaves it.
 */
export class EditHistory {
    private readonly done: Edit[] = [];
    private readonly undone: Edit[] = [];
    private readonly listeners: HistoryListener[] = [];
    /** Whether the last edit takes no more: sealed, undone or redone since. */
    private sealed = true;

    /** Applies `edit` and records it: the next undo() reverts it. */
    public do(edit: Edit): void {
        edit.apply();
        this.record(edit);
    }

    /**
     * Records `edit`, already applied: a change made live, as it was typed.
     * The last edit absorbs it when it can, and isn't sealed.
     */
    public record(edit: Edit): void {
        const last = this.done[this.done.length - 1];
        if (this.sealed || last?.absorb?.(edit) !== true) {
            this.done.push(edit);
        }

        this.sealed = false;
        this.undone.length = 0;
        this.changed();
    }

    /** Stops the last edit taking any more: what comes next is an edit of its own. */
    public seal(): void {
        this.sealed = true;
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
        this.sealed = true;
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
        this.sealed = true;
        this.changed();
    }

    /** Forgets every edit: after a save, or a reload, nothing before it can be undone. */
    public clear(): void {
        this.sealed = true;
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
