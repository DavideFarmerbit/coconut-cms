/**
 * One undo/redo stack for a single editing context. Works with any command that
 * exposes `undo()` returning (or resolving to) the next command to push onto the
 * opposite stack, LocalCommand and RemoteCommand both do this, so the stack never
 * needs to know which kind of command it's holding.
 *
 * Doesn't touch the DOM or show a pending state itself, undo()/redo() return whatever
 * command.undo() returns, a Promise for a RemoteCommand, a plain value for a
 * LocalCommand, callers await it and render a pending state around that if they need
 * to. Never optimistic: the stack only advances once the command's own undo() has
 * actually resolved.
 */
export class CommandStack {
    #undoStack = [];
    #redoStack = [];

    /** A new action always invalidates whatever was available to redo. */
    push(command) {
        this.#undoStack.push(command);
        this.#redoStack = [];
    }

    canUndo() {
        return this.#undoStack.length > 0;
    }

    canRedo() {
        return this.#redoStack.length > 0;
    }

    /** If undo() fails (a conflict, a network error), the command goes back onto the undo stack rather than being lost. */
    async undo() {
        const command = this.#undoStack.pop();
        if (!command) {
            return;
        }

        try {
            this.#redoStack.push(await command.undo());
        } catch (error) {
            this.#undoStack.push(command);
            throw error;
        }
    }

    /** Redoing is just undoing the undo, so it reuses the exact same command.undo() call, aimed at the redo stack instead. */
    async redo() {
        const command = this.#redoStack.pop();
        if (!command) {
            return;
        }

        try {
            this.#undoStack.push(await command.undo());
        } catch (error) {
            this.#redoStack.push(command);
            throw error;
        }
    }
}
