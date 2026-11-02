/** What a key press asks of an edit history. */
export type HistoryAction = 'undo' | 'redo';

/** The parts of a key press UndoKeys reads: a KeyboardEvent has them. */
export interface KeyPress {
    readonly key: string;
    readonly metaKey: boolean;
    readonly ctrlKey: boolean;
    readonly shiftKey: boolean;
    readonly altKey: boolean;
}

/**
 * Reads undo and redo off key presses: Cmd or Ctrl and Z undoes, Shift with
 * them redoes, and so does Ctrl and Y. A text field being typed in keeps the
 * browser's own undo of what's typed: a value becomes an edit once it's
 * committed.
 */
export class UndoKeys {
    /** What `press` asks: undo, redo, or nothing. */
    public static actionOf(press: KeyPress): HistoryAction | null {
        if (!(press.metaKey || press.ctrlKey) || press.altKey) {
            return null;
        }

        const key = press.key.toLowerCase();
        if (key === 'z') {
            return press.shiftKey ? 'redo' : 'undo';
        }

        return key === 'y' && press.ctrlKey && !press.shiftKey ? 'redo' : null;
    }

    /** Whether `target` takes typed text itself: its own undo comes first there. */
    public static isTyping(target: EventTarget | null): boolean {
        if (!(target instanceof HTMLElement)) {
            return false;
        }

        if (target.isContentEditable || target instanceof HTMLTextAreaElement) {
            return true;
        }

        return target instanceof HTMLInputElement && !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file'].includes(target.type);
    }
}
