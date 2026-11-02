/**
 * One change a user made, undoable: a value set, items removed, a field
 * renamed. It holds what it changes and what it needs to put it back, so it
 * applies and reverts on its own, whatever came after it. An EditHistory
 * runs it: apply() when done or redone, revert() when undone, always in
 * turn. A plugin's editor writes its own.
 */
export interface Edit {
    /** What it did, as a menu names it: "Remove 3 items", "Rename price". */
    readonly label: string;

    /** Makes the change. */
    apply(): void;

    /** Puts back what apply() changed. */
    revert(): void;

    /**
     * Takes `next`, done right after it, into itself, so one undo reverts
     * both: a field typed letter by letter is one edit. False keeps them
     * apart. Without it, an edit takes none. The history asks only while
     * this is the last edit, and not sealed.
     */
    absorb?(next: Edit): boolean;
}
