/** An entity as coconut-cms:entities.get, create and update give it: its type, its id, its values in stored form. */
export interface EntityData {
    readonly type: string;
    readonly id: string;
    readonly values: Readonly<Record<string, unknown>>;
}
