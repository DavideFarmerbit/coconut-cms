import {ChoicePicker} from '../../dom/ChoicePicker.ts';
import {Elements} from '../../dom/Elements.ts';
import {LocalChoices} from '../../dom/LocalChoices.ts';
import {ActionIcon} from '../../icons/ActionIcon.ts';
import type {CollectionItems} from '../../public/properties/CollectionItems.ts';
import type {PropertyContext} from '../../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../../public/properties/PropertyEditor.ts';
import {NewValues} from '../NewValues.ts';

/**
 * A Collection, like Unreal's arrays: its item count and how many were
 * removed, add and clear beside its name, then a row per item, each with its
 * own actions in the row: move up, move down, duplicate, remove. Items change
 * through the context's CollectionItems, so each keeps where it came from;
 * the session tells each change as a change of shape, and the rows follow.
 */
export class CollectionEditor implements PropertyEditor {
    public readonly widget: HTMLElement;
    public readonly children: HTMLElement | null;

    public constructor(private readonly context: PropertyContext) {
        const items = context.items;
        if (items === null) {
            this.widget = Elements.create('span', {classes: ['muted'], text: 'Not a Collection.'});
            this.children = null;

            return;
        }

        const count = items.values.length;
        const add = ActionIcon.button('add', 'Add an item');
        add.disabled = !context.writable;
        add.addEventListener('click', () => this.withNewItem(add, (item) => items.insert(item)));
        const clear = ActionIcon.button('clear', 'Remove every item');
        clear.disabled = !context.writable || count === 0;
        clear.addEventListener('click', () => items.clear());

        const removed = items.removedCount;
        this.widget = Elements.create('span', {classes: ['property__inline']}, [
            Elements.create('span', {classes: ['collection__count'], text: `${count} item${count === 1 ? '' : 's'}`}),
            ...(removed === 0 ? [] : [Elements.create('span', {classes: ['collection__removed'], text: `${removed} removed`})]),
            add,
            clear,
        ]);
        this.children = count === 0 ? null : Elements.create('div', {classes: ['properties', 'collection__items']}, items.values.map((_item, index) => this.itemRow(items, index)));
    }

    /** Item `index`'s row, its actions in it. */
    private itemRow(items: CollectionItems, index: number): HTMLElement {
        const row = this.context.itemOf(index);
        if (!this.context.writable) {
            return row;
        }

        const up = ActionIcon.button('moveUp', 'Move up');
        up.disabled = index === 0;
        up.addEventListener('click', () => items.move(index, index - 1));
        const down = ActionIcon.button('moveDown', 'Move down');
        down.disabled = index === items.values.length - 1;
        down.addEventListener('click', () => items.move(index, index + 1));
        const duplicate = ActionIcon.button('duplicate', 'Duplicate');
        duplicate.addEventListener('click', () => items.duplicate(index));
        const remove = ActionIcon.button('remove', 'Remove');
        remove.addEventListener('click', () => items.remove(index));

        row.querySelector(':scope > .property__header > .property__actions')?.append(up, down, duplicate, remove);

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
            ChoicePicker.open(anchor, new LocalChoices(types.map((type) => ({key: type.identifier, label: type.identifier, value: type.identifier}))), {
                placeholder: 'Search classes',
                picked: (type) => use(NewValues.object(this.context.catalog, type)),
            });

            return;
        }

        use(item.kind === 'owning_reference' ? NewValues.object(this.context.catalog, item.target ?? '') : NewValues.of(this.context.catalog, item));
    }
}
