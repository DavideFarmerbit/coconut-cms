import {Elements} from '../dom/Elements.ts';
import {ActionIcon} from '../icons/ActionIcon.ts';
import {TypeIcon} from '../icons/TypeIcon.ts';
import type {ApiClient} from '../public/api/ApiClient.ts';
import type {PropertyContext} from '../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../public/properties/PropertyEditor.ts';
import type {PropertyEditors} from '../public/properties/PropertyEditors.ts';
import {PropertyPath} from '../public/properties/PropertyPath.ts';
import type {PropertySlot} from '../public/properties/PropertySlot.ts';
import type {ObjectValue} from '../public/properties/RecordValues.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {FieldDescription, ValueDescription} from '../public/types/TypeDescription.ts';
import {DraftCollectionItems} from './DraftCollectionItems.ts';
import type {EntityDraft} from './EntityDraft.ts';
import {NewValues} from './NewValues.ts';
import type {ReferenceLabels} from './ReferenceLabels.ts';
import {Values} from './Values.ts';

/** One property to show: what a row is built from. */
interface PropertySpec {
    readonly label: string;
    readonly path: string;
    readonly value: ValueDescription;
    readonly slot: PropertySlot;
    readonly writable: boolean;
    /** The chain of the type holding it, itself first; empty for a Collection's item. */
    readonly holderChain: readonly string[];
    /** Its field's name; null for a Collection's item. */
    readonly field: string | null;
}

/** Told that a property was collapsed or expanded. */
export type CollapseChanged = (path: string, collapsed: boolean) => void;

/** A row shown, with what refresh() updates in it. */
interface ShownRow {
    readonly row: HTMLElement;
    readonly spec: PropertySpec;
    /** Where an item came from: "new", "from [2]". */
    readonly origin: HTMLElement;
    /** What a new object holds there; undefined when nothing does: a Collection's item. */
    readonly defaultValue: unknown;
}

/**
 * Builds property rows for an entity being edited. A row reads: a chevron
 * collapsing its children when it has some, its type icon, its name (an
 * item's with where it came from), the value widget admin.properties resolves
 * for it, its actions (a Collection item's), and two resets: to what was
 * saved, once it changed, and to its default, while it differs from that;
 * then its error, and its children. Every slot it hands out clears its
 * property's error once the property is edited. After a change that keeps
 * the rows, refresh() marks them again.
 */
export class PropertyRows {
    private readonly shown: ShownRow[] = [];

    public constructor(
        private readonly draft: EntityDraft,
        private readonly catalog: TypeCatalog,
        private readonly api: ApiClient,
        private readonly editors: PropertyEditors,
        private readonly labels: ReferenceLabels,
        /** Each property's error, by path, as the last save gave them. */
        private readonly errors: Map<string, string>,
        /** The paths of the properties collapsed. */
        private readonly collapsed: ReadonlySet<string>,
        private readonly onCollapse: CollapseChanged,
        private readonly rebuild: () => void,
    ) {
    }

    /**
     * The rows of `fields` of an object of type `type`, holding `values` at
     * `prefix` (null for the entity), joined to them by `join`. A field the
     * user may not read, which a read left out, isn't shown.
     */
    public fields(type: string, values: Record<string, unknown>, fields: readonly {readonly name: string; readonly field: FieldDescription}[], prefix: string | null, join: string): HTMLElement {
        const holderChain = [type, ...this.catalog.ancestorsOf(type).map((ancestor) => ancestor.identifier)];

        return Elements.create('div', {classes: ['properties']}, fields
            .filter(({name, field}) => field.read && name in values)
            .map(({name, field}) => {
                const path = PropertyPath.child(prefix, join, name);

                return this.row({
                    label: name,
                    path,
                    value: field.value,
                    slot: this.draft.slot(values, name, path, field.value),
                    writable: field.write,
                    holderChain,
                    field: name,
                });
            }));
    }

    /**
     * Marks every row shown by where it stands: changed, new or moved, at
     * its default or not. What a change that keeps the rows needs.
     */
    public refresh(): void {
        for (const {row, spec, origin, defaultValue} of this.shown) {
            const status = this.draft.statusAt(spec.path);
            row.classList.toggle('is-new', status.isNew);
            row.classList.toggle('is-changed', status.isChanged && !status.isNew);
            row.classList.toggle('is-moved', status.movedFrom !== null);
            row.classList.toggle('has-default', defaultValue !== undefined && spec.writable && !Values.sameContent(spec.slot.get(), defaultValue));
            // Inside a new item, every property is new too: only the item says so.
            origin.textContent = status.movedFrom !== null ? `from [${status.movedFrom}]` : status.isNew && spec.field === null && !this.isInNewItem(spec.path) ? 'new' : '';
        }
    }

    /** The row of the property `spec`. */
    private row(spec: PropertySpec): HTMLElement {
        const error = Elements.create('p', {classes: ['property__error'], text: this.errors.get(spec.path) ?? ''});
        const edited = (): void => {
            // An edit answers the error the last save gave this property.
            this.errors.delete(spec.path);
            error.textContent = '';
        };
        const slot: PropertySlot = {
            get: () => spec.slot.get(),
            set: (value) => {
                edited();
                spec.slot.set(value);
            },
        };
        const editor = this.editorFor(spec, slot, edited);
        const hasChildren = editor.children !== null;
        const collapsed = hasChildren && this.collapsed.has(spec.path);
        const defaultValue = spec.field === null ? undefined : this.catalog.get(spec.holderChain[0] ?? '')?.defaults[spec.field];

        const reset = ActionIcon.button('reset', 'Reset to saved');
        reset.classList.add('property__reset');
        reset.addEventListener('click', () => {
            this.draft.resetAt(spec.path);
            this.rebuild();
        });
        const toDefault = ActionIcon.button('default', 'Reset to default');
        toDefault.classList.add('property__default');
        toDefault.addEventListener('click', () => {
            slot.set(NewValues.keepingIds(defaultValue, slot.get()));
            this.rebuild();
        });
        const origin = Elements.create('span', {classes: ['property__origin']});

        const row = Elements.create('div', {
            classes: [
                'property',
                `property--${spec.value.kind}`,
                ...(spec.field === null ? ['is-item'] : []),
                ...(hasChildren ? ['has-children'] : []),
                ...(collapsed ? ['is-collapsed'] : []),
                ...(spec.writable ? [] : ['is-readonly']),
            ],
            // Only the label is indented by its depth: every value lines up in one column, as in Unreal.
            attributes: {'data-path': spec.path, style: `--cc-property-depth: ${PropertyPath.segments(spec.path).length - 1}`},
        });

        const header = Elements.create('div', {classes: ['property__header']}, [
            Elements.create('div', {classes: ['property__label']}, [
                hasChildren ? this.toggle(row, spec.path) : Elements.create('span', {classes: ['property__toggle-space']}),
                TypeIcon.of(spec.value),
                Elements.create('span', {classes: ['property__name'], text: spec.label}),
                origin,
            ]),
            Elements.create('div', {classes: ['property__value']}, [editor.widget]),
            Elements.create('div', {classes: ['property__actions']}),
            Elements.create('div', {classes: ['property__resets']}, [toDefault, reset]),
        ]);

        row.append(header, error, ...(editor.children === null ? [] : [Elements.create('div', {classes: ['property__children']}, [editor.children])]));
        this.shown.push({row, spec, origin, defaultValue});

        return row;
    }

    /** Whether the item at `path` lies in a new item: its Collection is new itself. */
    private isInNewItem(path: string): boolean {
        return this.draft.statusAt(path.slice(0, path.lastIndexOf(PropertyPath.TRAVERSE))).isNew;
    }

    /** The chevron collapsing and expanding `row`'s children, remembered by `path`. */
    private toggle(row: HTMLElement, path: string): HTMLElement {
        const toggle = Elements.create('button', {
            classes: ['property__toggle'],
            attributes: {type: 'button', 'aria-label': 'Collapse or expand', 'aria-expanded': String(!row.classList.contains('is-collapsed'))},
        }, [ActionIcon.svg('expand')]);
        toggle.addEventListener('click', () => {
            const collapsed = row.classList.toggle('is-collapsed');
            toggle.setAttribute('aria-expanded', String(!collapsed));
            this.onCollapse(path, collapsed);
        });

        return toggle;
    }

    /** The editor admin.properties resolves for `spec`, built with its context; `edited` is told of every change of a Collection's items. */
    private editorFor(spec: PropertySpec, slot: PropertySlot, edited: () => void): PropertyEditor {
        const context: PropertyContext = {
            path: spec.path,
            value: spec.value,
            slot,
            writable: spec.writable,
            items: spec.value.kind === 'collection' ? new DraftCollectionItems(this.draft, spec.slot, spec.path, spec.value, edited) : null,
            catalog: this.catalog,
            api: this.api,
            // A Collection's Embed item is its own row: its fields are traversed, "wheels.1.size", never embedded.
            fieldsOf: (object: ObjectValue, join: string) => this.fields(object.type, object.values, this.catalog.fieldsOf(object.type), spec.path, spec.field === null ? PropertyPath.TRAVERSE : join),
            itemOf: (index: number) => this.item(spec, index),
            labelOf: (reference) => this.labels.of(reference),
            rebuild: () => this.rebuild(),
        };
        const Editor = this.editors.resolve(spec.holderChain, spec.field, spec.value);
        if (Editor === undefined) {
            return {widget: Elements.create('span', {classes: ['muted'], text: `No editor for a ${spec.value.kind}.`}), children: null};
        }

        return new Editor(context);
    }

    /** The row of item `index` of the Collection `collection` holds. */
    private item(collection: PropertySpec, index: number): HTMLElement {
        const items = collection.slot.get();
        const item = collection.value.item;
        if (!Array.isArray(items) || item === null) {
            return Elements.create('div');
        }

        const path = PropertyPath.child(collection.path, PropertyPath.TRAVERSE, index);

        return this.row({
            label: `[${index}]`,
            path,
            value: item,
            slot: this.draft.slot(items, index, path, item),
            writable: collection.writable,
            holderChain: [],
            field: null,
        });
    }
}
