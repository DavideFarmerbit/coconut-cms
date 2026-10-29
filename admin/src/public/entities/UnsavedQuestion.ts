/** What the user answers when the last view of an entity with unsaved changes lets it go. */
export type UnsavedAnswer = 'save' | 'discard' | 'cancel';

/**
 * Asks what to do with an entity's unsaved changes. The EntityStore asks it,
 * so the store stays free of the DOM: the admin's asks with a dialog, a
 * test's answers at once.
 */
export interface UnsavedQuestion {
    /** Asks about the entity called `label`. */
    ask(label: string): Promise<UnsavedAnswer>;
}
