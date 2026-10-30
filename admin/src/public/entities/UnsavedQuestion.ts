/** What the user answers when the last view of an entity with unsaved changes lets it go. */
export type UnsavedAnswer = 'save' | 'discard' | 'cancel';

/**
 * Asks what to do with unsaved changes. The EntityStore asks it, so the
 * store stays free of the DOM: the admin's asks with a dialog, a test's
 * answers at once.
 */
export interface UnsavedQuestion {
    /** Asks about the entity called `label`: Save, Discard or Cancel. */
    ask(label: string): Promise<UnsavedAnswer>;

    /** Asks once about every entity called in `labels`: Save all, Discard all or Cancel. */
    askAll(labels: readonly string[]): Promise<UnsavedAnswer>;
}
