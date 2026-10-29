import type {Choice} from './Choice.ts';
import type {ChoicePage, ChoiceSource} from './ChoiceSource.ts';

/** A ChoiceSource of a list at hand: one page, narrowed by labels holding the search. */
export class LocalChoices<T> implements ChoiceSource<T> {
    public readonly searchable = true;
    public readonly searchDelay = 0;

    public constructor(private readonly choices: readonly Choice<T>[]) {
    }

    public page(search: string, next: boolean): Promise<ChoicePage<T>> {
        const text = search.trim().toLowerCase();

        return Promise.resolve({choices: next ? [] : this.choices.filter((choice) => choice.label.toLowerCase().includes(text)), hasMore: false});
    }
}
