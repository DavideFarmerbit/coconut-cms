import {Elements} from '../dom/Elements.ts';
import type {ApiClient} from '../public/api/ApiClient.ts';
import {EntityLabels} from '../public/properties/EntityLabels.ts';
import type {ReferenceValue} from '../public/properties/RecordValues.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';

/** Told what the picker picked: an entity, or null for none. */
export type Picked = (reference: ReferenceValue | null) => void;

/** One page of coconut-cms:entities.query, as far as the picker reads it. */
interface EntityPage {
    readonly items: readonly {readonly type: string; readonly id: string; readonly values: Readonly<Record<string, unknown>>}[];
    readonly nextCursor: string | null;
}

/**
 * The dropdown picking a Reference's target: the target type's standalone
 * entities, subtypes included, by label, a page at a time, narrowed by a
 * search on the label field when it's a String; "None" clears it. It opens
 * under its button, or above it where there's no room below. One is open
 * at a time; Escape or a click elsewhere closes it.
 */
export class ReferencePicker {
    /** How many entities a page lists. */
    private static readonly PAGE = 20;

    /** The gap between the picker and the button opening it, in pixels. */
    private static readonly GAP = 4;

    /** The button it opened from: where it's placed. */
    private anchor: HTMLElement | undefined;

    private static current: ReferencePicker | undefined;

    private readonly element: HTMLElement;
    private readonly search: HTMLInputElement;
    private readonly list: HTMLElement;
    private readonly more: HTMLButtonElement;
    private readonly labelField: string | null;
    private cursor: string | null = null;
    private searchTimer: ReturnType<typeof setTimeout> | undefined;
    private readonly closeOnOutside = (event: Event): void => {
        if (!(event.target instanceof Node) || !this.element.contains(event.target)) {
            this.close();
        }
    };
    private readonly closeOnEscape = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            this.close();
        }
    };

    private constructor(
        private readonly api: ApiClient,
        private readonly catalog: TypeCatalog,
        private readonly type: string,
        private readonly picked: Picked,
    ) {
        this.labelField = EntityLabels.fieldOf(catalog, type);
        this.search = Elements.create('input', {classes: ['field__input'], attributes: {placeholder: this.labelField === null ? 'No field to search by' : `Search ${type}`}});
        this.search.disabled = this.labelField === null;
        this.search.addEventListener('input', () => {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => void this.load(true), 250);
        });
        this.list = Elements.create('ul', {classes: ['picker__list']});
        this.more = Elements.create('button', {classes: ['button', 'button--quiet'], text: 'More', attributes: {type: 'button'}});
        this.more.addEventListener('click', () => void this.load(false));
        const none = Elements.create('button', {classes: ['context-menu__item'], text: 'None', attributes: {type: 'button'}});
        none.addEventListener('click', () => this.choose(null));
        this.element = Elements.create('div', {classes: ['picker']}, [this.search, Elements.create('ul', {classes: ['picker__list']}, [Elements.create('li', {}, [none])]), this.list, this.more]);
    }

    /** Opens a picker of `type`'s entities under `anchor`, closing any other. */
    public static open(anchor: HTMLElement, api: ApiClient, catalog: TypeCatalog, type: string, picked: Picked): void {
        ReferencePicker.current?.close();
        const picker = new ReferencePicker(api, catalog, type, picked);
        ReferencePicker.current = picker;
        document.body.append(picker.element);
        picker.anchor = anchor;
        picker.place();
        picker.search.focus();
        setTimeout(() => {
            document.addEventListener('pointerdown', picker.closeOnOutside);
            document.addEventListener('keydown', picker.closeOnEscape);
        });
        void picker.load(true);
    }

    /** Lists the first page, or the next one, as the search says. */
    private async load(fresh: boolean): Promise<void> {
        if (fresh) {
            this.cursor = null;
            this.list.replaceChildren();
        }

        const text = this.search.value.trim();
        const page = await this.api.call<EntityPage>('coconut-cms:entities.query', {
            type: this.type,
            limit: ReferencePicker.PAGE,
            ...(this.cursor === null ? {} : {after: this.cursor}),
            ...(this.labelField === null ? {} : {orderBy: [{path: this.labelField}]}),
            ...(this.labelField === null || text === '' ? {} : {where: [{path: this.labelField, operator: 'like', values: [`%${text}%`]}]}),
        });
        this.cursor = page.nextCursor;
        this.more.hidden = page.nextCursor === null;
        this.list.append(...page.items.map((entity) => {
            const button = Elements.create('button', {
                classes: ['context-menu__item'],
                text: EntityLabels.of(this.catalog, entity.type, entity.id, entity.values),
                attributes: {type: 'button'},
            });
            button.addEventListener('click', () => this.choose({type: entity.type, id: entity.id}));

            return Elements.create('li', {}, [button]);
        }));
        if (this.list.childElementCount === 0) {
            this.list.append(Elements.create('li', {classes: ['list__note', 'muted'], text: 'Nothing found.'}));
        }

        // Its height is known only now.
        this.place();
    }

    /** Places it under its anchor, or above it where there's no room below, inside the window. */
    private place(): void {
        if (this.anchor === undefined) {
            return;
        }

        const anchor = this.anchor.getBoundingClientRect();
        const {offsetWidth: width, offsetHeight: height} = this.element;
        const below = anchor.bottom + ReferencePicker.GAP;
        const top = below + height <= window.innerHeight ? below : anchor.top - height - ReferencePicker.GAP;
        this.element.style.top = `${Math.max(0, top)}px`;
        this.element.style.left = `${Math.max(0, Math.min(anchor.left, window.innerWidth - width))}px`;
    }

    /** Closes, telling what was picked. */
    private choose(reference: ReferenceValue | null): void {
        this.close();
        this.picked(reference);
    }

    private close(): void {
        document.removeEventListener('pointerdown', this.closeOnOutside);
        document.removeEventListener('keydown', this.closeOnEscape);
        this.element.remove();
        if (ReferencePicker.current === this) {
            ReferencePicker.current = undefined;
        }
    }
}
