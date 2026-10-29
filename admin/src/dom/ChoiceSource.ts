import type {Choice} from './Choice.ts';

/** One page of choices a ChoiceSource gives. */
export interface ChoicePage<T> {
    readonly choices: readonly Choice<T>[];
    /** Whether a next page follows. */
    readonly hasMore: boolean;
}

/**
 * Where a ChoicePicker's choices come from, a page at a time, narrowed by
 * its search box: a fixed list, a query to the backend.
 */
export interface ChoiceSource<T> {
    /** Whether a search can narrow it; when not, the search box is disabled. */
    readonly searchable: boolean;

    /** How long typing settles before a search, in milliseconds: none for a list at hand, some for a query. */
    readonly searchDelay: number;

    /** The first page of what `search` finds, or, when `next`, the page after the last one given. */
    page(search: string, next: boolean): Promise<ChoicePage<T>>;
}
