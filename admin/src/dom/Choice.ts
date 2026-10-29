/** One choice of a ChoicePicker: what it shows, and what picking it gives. */
export interface Choice<T> {
    /** Tells it apart from the others: how the picker knows the current one. */
    readonly key: string;
    readonly label: string;
    readonly value: T;
}
