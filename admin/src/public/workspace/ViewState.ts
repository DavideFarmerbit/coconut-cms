import type {JsonValue} from './Json.ts';

/**
 * A view's own saved state, by name, kept with the layout: what it shows
 * again after a reload, such as the item selected. JSON values only.
 */
export interface ViewState {
    /** The value saved as `name`; undefined when there's none. */
    get(name: string): JsonValue | undefined;

    /** Saves `value` as `name`. */
    set(name: string, value: JsonValue): void;
}
