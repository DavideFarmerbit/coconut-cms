/**
 * Where a property's value lives in the entity being edited: reading it
 * gives the value now, in the form a read gives; setting it changes the
 * working copy and marks the property changed. Nothing is saved until the
 * entity is.
 */
export interface PropertySlot {
    /** The value now. */
    get(): unknown;

    /** Changes the value. */
    set(value: unknown): void;
}
