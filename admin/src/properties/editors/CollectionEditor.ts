import {ContextMenu} from '../../dom/ContextMenu.ts';
import {Elements} from '../../dom/Elements.ts';
import {Tooltips} from '../../dom/Tooltips.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';
import {isObjectValue} from '../../public/properties/RecordValues.ts';
import {NewValues} from '../NewValues.ts';

/**
 * A Collection, like Unreal's arrays: its item count, add and clear beside
 * its name, then a row per item, each with a menu to insert before,
 * duplicate, remove, and move up or down. Changing its items replaces the
 * whole list; an owned item keeps its id through a move.
 */
export class CollectionEditor implements PropertyEditor {
    public readonly widget: HTMLElement;
    public readonly children: HTMLElement | null;

    public constructor(private readonly context: PropertyContext) {
        const items = this.items();
        const add = Elements.create('button', {classes: ['icon-button'], text: '+', attributes: {type: 'button', 'aria-label': 'Add an item', ...Tooltips.attributes('Add an item', 'top')}});
        add.disabled = !context.writable;
        add.addEventListener('click', () => this.withNewItem(add, (item) => this.replace([...this.items(), item])));
        const clear = Elements.create('button', {classes: ['icon-button'], text: '⌫', attributes: {type: 'button', 'aria-label': 'Remove every item', ...Tooltips.attributes('Remove every item', 'top')}});
        clear.disabled = !context.writable || items.length === 0;
        clear.addEventListener('click', () => this.replace([]));

        this.widget = Elements.create('span', {classes: ['property__inline']}, [
            Elements.create('span', {classes: ['muted'], text: `${items.length} item${items.length === 1 ? '' : 's'}`}),
            add,
            clear,
        ]);
        this.children = items.length === 0 ? null : Elements.create('div', {classes: ['properties']}, items.map((_item, index) => this.itemRow(index)));
    }

    /** Item `index`'s row, with its menu in its header. */
    private itemRow(index: number): HTMLElement {
        const row = this.context.itemOf(index);
        if (!this.context.writable) {
            return row;
        }

        const menu = Elements.create('button', {classes: ['icon-button'], text: '⋮', attributes: {type: 'button', 'aria-label': 'Item actions'}});
        menu.addEventListener('click', () => ContextMenu.below(menu, [
            {label: 'Insert before', run: () => this.withNewItem(menu, (item) => this.replace(CollectionEditor.spliced(this.items(), index, 0, item)))},
            {label: 'Duplicate', run: () => this.replace(CollectionEditor.spliced(this.items(), index + 1, 0, CollectionEditor.copy(this.items()[index])))},
            {label: 'Remove', run: () => this.replace(CollectionEditor.spliced(this.items(), index, 1))},
            ...(index > 0 ? [{label: 'Move up', run: () => this.move(index, index - 1)}] : []),
            ...(index < this.items().length - 1 ? [{label: 'Move down', run: () => this.move(index, index + 1)}] : []),
        ]));
        row.querySelector('.property__header')?.append(menu);

        return row;
    }

    /**
     * Makes a new item and gives it to `use`. An owned item whose target has
     * subtypes asks for its type first, under `anchor`.
     */
    private withNewItem(anchor: HTMLElement, use: (item: unknown) => void): void {
        const item = this.context.value.item;
        if (item === null) {
            return;
        }

        const types = item.kind === 'owning_reference' ? this.context.catalog.withSubtypes(item.target ?? '') : [];
        if (types.length > 1) {
            ContextMenu.below(anchor, types.map((type) => ({label: type.identifier, run: () => use(NewValues.object(this.context.catalog, type.identifier))})));

            return;
        }

        use(item.kind === 'owning_reference' ? NewValues.object(this.context.catalog, item.target ?? '') : NewValues.of(this.context.catalog, item));
    }

    /** Moves item `from` to `to`. */
    private move(from: number, to: number): void {
        const items = [...this.items()];
        const [moved] = items.splice(from, 1);
        items.splice(to, 0, moved);
        this.replace(items);
    }

    /** Replaces every item with `items`, then shows them. */
    private replace(items: readonly unknown[]): void {
        this.context.slot.set([...items]);
        this.context.rebuild();
    }

    /** The items now. */
    private items(): readonly unknown[] {
        const items = this.context.slot.get();

        return Array.isArray(items) ? items : [];
    }

    /** A copy of `items`, `remove` of them taken out at `start` and `insert` put there. */
    private static spliced(items: readonly unknown[], start: number, remove: number, ...insert: unknown[]): unknown[] {
        const copy = [...items];
        copy.splice(start, remove, ...insert);

        return copy;
    }

    /** A copy of `item`: a duplicated owned object is a new one, with no id. */
    private static copy(item: unknown): unknown {
        const copy = structuredClone(item);

        return isObjectValue(copy) ? {...copy, id: null} : copy;
    }
}
