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
import type {EntityDraft} from './EntityDraft.ts';
import type {ReferenceLabels} from './ReferenceLabels.ts';

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

/**
 * Builds property rows for an entity being edited. A row reads: a chevron
 * collapsing its children when it has some, its type icon, its name, the
 * value widget admin.properties resolves for it, its actions (a Collection
 * item's), and a reset button once it changed; then its error, and its
 * children. Every slot it hands out clears its property's error once the
 * property is edited.
 */
export class PropertyRows {
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

    /** The row of the property `spec`. */
    private row(spec: PropertySpec): HTMLElement {
        const error = Elements.create('p', {classes: ['property__error'], text: this.errors.get(spec.path) ?? ''});
        const slot: PropertySlot = {
            get: () => spec.slot.get(),
            set: (value) => {
                // An edit answers the error the last save gave this property.
                this.errors.delete(spec.path);
                error.textContent = '';
                spec.slot.set(value);
            },
        };
        const editor = this.editorFor(spec, slot);
        const hasChildren = editor.children !== null;
        const collapsed = hasChildren && this.collapsed.has(spec.path);

        const reset = ActionIcon.button('reset', 'Reset to saved');
        reset.classList.add('property__reset');
        reset.addEventListener('click', () => {
            this.draft.resetAt(spec.path);
            this.rebuild();
        });

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
            ]),
            Elements.create('div', {classes: ['property__value']}, [editor.widget]),
            Elements.create('div', {classes: ['property__actions']}),
            reset,
        ]);

        row.append(header, error, ...(editor.children === null ? [] : [Elements.create('div', {classes: ['property__children']}, [editor.children])]));

        return row;
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

    /** The editor admin.properties resolves for `spec`, built with its context. */
    private editorFor(spec: PropertySpec, slot: PropertySlot): PropertyEditor {
        const context: PropertyContext = {
            path: spec.path,
            value: spec.value,
            slot,
            writable: spec.writable,
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
