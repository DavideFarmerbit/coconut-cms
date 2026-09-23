/**
 * A command that reverts synchronously, in memory, no server involved, typing into a
 * field before it's ever been saved anywhere.
 *
 * undo() performs the revert and returns a new LocalCommand with the two functions
 * swapped, so calling undo() on *that* one redoes the original. Redo is never a
 * separate mechanism, it's just undoing the undo.
 */
export class LocalCommand {
    #undo;
    #redo;

    constructor(undo, redo) {
        this.#undo = undo;
        this.#redo = redo;
    }

    undo() {
        this.#undo();

        return new LocalCommand(this.#redo, this.#undo);
    }
}
