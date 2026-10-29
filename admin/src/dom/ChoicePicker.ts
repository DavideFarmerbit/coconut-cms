import type {Choice} from './Choice.ts';
import type {ChoiceSource} from './ChoiceSource.ts';
import {Elements} from './Elements.ts';
import {Popup} from './Popup.ts';

/** How a ChoicePicker opens. */
export interface ChoiceOptions<T> {
    /** The search box's placeholder. */
    readonly placeholder: string;
    /** Choices always listed first, whatever the search: "None". */
    readonly pinned?: readonly Choice<T>[];
    /** The key of the choice standing now, marked in the list. */
    readonly current?: string;
    /** Told what was picked. */
    readonly picked: (value: T) => void;
}

/**
 * The admin's dropdown, in place of the browser's select: a search box,
 * then the choices its source gives for it, a page at a time with "More";
 * the current one marked. Arrow keys move through the choices, Enter picks;
 * a click picks too. What it lists is up to its source: a list at hand,
 * entities found by a query. It's a Popup under its anchor.
 *
 *     ChoicePicker.open(button, new LocalChoices(types), {placeholder: 'Search classes', current: type, picked: (type) => ...});
 */
export class ChoicePicker<T> {
    private readonly element: HTMLElement;
    private readonly search: HTMLInputElement;
    private readonly list: HTMLElement;
    private readonly more: HTMLButtonElement;
    private readonly pinned: readonly Choice<T>[];
    private popup: Popup | undefined;
    /** What the source gave for the search so far. */
    private loaded: Choice<T>[] = [];
    private hasMore = false;
    /** Which choice Enter picks, among the pinned and loaded ones. */
    private active = 0;
    /** Whether the keyboard was used: only then is the choice Enter picks highlighted, so it never reads as a hover. */
    private keyed = false;
    /** Counts the loads asked for: an answer to an older one, overtaken by typing, is dropped. */
    private loads = 0;
    private searchTimer: ReturnType<typeof setTimeout> | undefined;

    private constructor(
        private readonly source: ChoiceSource<T>,
        private readonly options: ChoiceOptions<T>,
    ) {
        this.pinned = options.pinned ?? [];
        this.search = Elements.create('input', {classes: ['field__input'], attributes: {placeholder: source.searchable ? options.placeholder : 'Nothing to search by'}});
        this.search.disabled = !source.searchable;
        this.search.addEventListener('input', () => {
            this.keyed = true;
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => void this.load(false), source.searchDelay);
        });
        this.search.addEventListener('keydown', (event) => this.key(event));
        this.list = Elements.create('ul', {classes: ['picker__list']});
        this.more = Elements.create('button', {classes: ['button', 'button--quiet'], text: 'More', attributes: {type: 'button'}});
        this.more.hidden = true;
        this.more.addEventListener('click', () => void this.load(true));
        this.element = Elements.create('div', {classes: ['picker']}, [this.search, this.list, this.more]);
    }

    /** Opens a picker of `source`'s choices under `anchor`, closing any other popup. */
    public static open<T>(anchor: HTMLElement, source: ChoiceSource<T>, options: ChoiceOptions<T>): void {
        const picker = new ChoicePicker(source, options);
        picker.popup = Popup.open(anchor, picker.element);
        picker.search.focus();
        void picker.load(false);
    }

    /** Every choice listed: the pinned ones, then the loaded ones. */
    private get choices(): readonly Choice<T>[] {
        return [...this.pinned, ...this.loaded];
    }

    /**
     * Lists the first page of what the search finds, or, when `next`, adds
     * the next page. A first page puts Enter on the current choice when
     * just opened, on the first found after a search.
     */
    private async load(next: boolean): Promise<void> {
        const load = ++this.loads;
        const page = await this.source.page(this.search.value, next);
        if (load !== this.loads) {
            return;
        }

        this.loaded = next ? [...this.loaded, ...page.choices] : [...page.choices];
        this.hasMore = page.hasMore;
        if (!next) {
            const current = this.choices.findIndex((choice) => choice.key === this.options.current);
            this.active = this.keyed ? (this.loaded.length > 0 ? this.pinned.length : 0) : Math.max(0, current);
        }
        this.render();
    }

    /** Shows the choices, the current one marked, and the one Enter picks once the keyboard was used. */
    private render(): void {
        const choices = this.choices;
        this.list.replaceChildren(...choices.map((choice, index) => {
            const button = Elements.create('button', {
                classes: [
                    'context-menu__item',
                    ...(this.keyed && index === this.active ? ['is-active'] : []),
                    ...(choice.key === this.options.current ? ['is-current'] : []),
                ],
                text: choice.label,
                attributes: {type: 'button'},
            });
            button.addEventListener('click', () => this.choose(choice));

            return Elements.create('li', {}, [button]);
        }));
        if (this.loaded.length === 0) {
            this.list.append(Elements.create('li', {classes: ['list__note', 'muted'], text: 'Nothing found.'}));
        }
        this.more.hidden = !this.hasMore;
        this.list.querySelector('.is-active')?.scrollIntoView({block: 'nearest'});

        // Its height is known only now.
        this.popup?.place();
    }

    /** Arrow keys move the choice Enter picks, past the last loaded into the next page; Enter picks it. */
    private key(event: KeyboardEvent): void {
        const count = this.choices.length;
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && count > 0) {
            event.preventDefault();
            // The first press shows where Enter stands; the next ones move it.
            const step = !this.keyed ? 0 : event.key === 'ArrowDown' ? 1 : -1;
            this.keyed = true;
            if (step === 1 && this.active === count - 1 && this.hasMore) {
                this.active = count;
                void this.load(true);

                return;
            }
            this.active = (this.active + step + count) % count;
            this.render();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const choice = this.choices[this.active];
            if (choice !== undefined) {
                this.choose(choice);
            }
        }
    }

    /** Closes, telling what was picked. */
    private choose(choice: Choice<T>): void {
        clearTimeout(this.searchTimer);
        this.popup?.close();
        this.options.picked(choice.value);
    }
}
