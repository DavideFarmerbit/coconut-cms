import {Elements} from '../dom/Elements.ts';
import {ActionIcon} from '../icons/ActionIcon.ts';
import {TypeIcon} from '../icons/TypeIcon.ts';
import type {PropertyContext} from '../public/properties/PropertyContext.ts';
import type {PropertyEditor} from '../public/properties/PropertyEditor.ts';
import type {PropertyInfo} from '../public/properties/PropertyInfo.ts';
import {PropertyPath} from '../public/properties/PropertyPath.ts';
import type {RowServices} from './RowServices.ts';
import {Values} from './Values.ts';

/**
 * One property's row, as shown: a chevron collapsing its children when it
 * has some, its type icon, its name (an item's with where it came from), the
 * value widget admin.properties resolves for it, its actions (a Collection
 * item's), and two resets: to its default, while it differs from that, and
 * to what was saved, once it changed; then its error, and its children.
 * Its changes go to the session in its own name: the row making a change
 * keeps its widget. A plain row, the parent of what a filter shows under
 * it, has only its name, its chevron and those rows.
 */
export class BuiltRow {
    public readonly element: HTMLElement;
    private readonly origin: HTMLElement;
    private readonly error: HTMLElement;

    /** @param plain Whether it's a plain parent row. */
    public constructor(
        private readonly services: RowServices,
        private readonly info: PropertyInfo,
        plain = false,
    ) {
        const path = info.path;
        const editor: PropertyEditor = plain ? {widget: Elements.create('span'), children: services.shownUnder(path)} : this.editor();
        const hasChildren = editor.children !== null;
        const collapsed = hasChildren && services.collapse.isCollapsed(path);
        this.origin = Elements.create('span', {classes: ['property__origin']});
        this.error = Elements.create('p', {classes: ['property__error']});

        this.element = Elements.create('div', {
            classes: [
                'property',
                `property--${info.value.kind}`,
                ...(info.field === null ? ['is-item'] : []),
                ...(hasChildren ? ['has-children'] : []),
                ...(collapsed ? ['is-collapsed'] : []),
                ...(info.writable ? [] : ['is-readonly']),
                ...(plain ? ['is-plain'] : []),
            ],
            // Only the label is indented by its depth: every value lines up in one column, as in Unreal.
            attributes: {'data-path': path, style: `--cc-property-depth: ${PropertyPath.segments(path).length - 1}`},
        });

        const header = Elements.create('div', {classes: ['property__header']}, [
            Elements.create('div', {classes: ['property__label']}, [
                hasChildren ? this.toggle() : Elements.create('span', {classes: ['property__toggle-space']}),
                TypeIcon.of(info.value),
                Elements.create('span', {classes: ['property__name'], text: BuiltRow.labelOf(info)}),
                this.origin,
            ]),
            Elements.create('div', {classes: ['property__value']}, [editor.widget]),
            Elements.create('div', {classes: ['property__actions']}),
            Elements.create('div', {classes: ['property__resets']}, plain ? [] : this.resets()),
        ]);

        this.element.append(header, this.error, ...(editor.children === null ? [] : [Elements.create('div', {classes: ['property__children']}, [editor.children])]));
    }

    /** Its path. */
    public get path(): string {
        return this.info.path;
    }

    /** Marks it by where it stands: changed, new or moved, at its default or not, its error. */
    public refresh(): void {
        const session = this.services.session;
        const path = this.info.path;
        const status = session.statusAt(path);
        const defaultValue = session.defaultAt(path);
        this.element.classList.toggle('is-new', status.isNew);
        this.element.classList.toggle('is-changed', status.isChanged && !status.isNew);
        this.element.classList.toggle('is-moved', status.movedFrom !== null);
        this.element.classList.toggle('has-default', defaultValue !== undefined && this.info.writable && !this.element.classList.contains('is-plain') && !Values.sameContent(session.valueAt(path), defaultValue));
        // Inside a new item, every property is new too: only the item says so.
        this.origin.textContent = status.movedFrom !== null ? `from [${status.movedFrom}]` : status.isNew && this.info.field === null && !this.isInNewItem() ? 'new' : '';
        this.error.textContent = session.errorAt(path) ?? '';
    }

    /** Its two resets: to its default, and to what was saved. CSS shows each while it applies. */
    private resets(): HTMLElement[] {
        const session = this.services.session;
        const path = this.info.path;
        const toDefault = ActionIcon.button('default', 'Reset to default');
        toDefault.classList.add('property__default');
        toDefault.addEventListener('click', () => session.resetToDefaultAt(path, this));
        const reset = ActionIcon.button('reset', 'Reset to saved');
        reset.classList.add('property__reset');
        reset.addEventListener('click', () => session.resetAt(path, this));

        return [toDefault, reset];
    }

    /** The editor admin.properties resolves for it, built with its context. */
    private editor(): PropertyEditor {
        const {session, catalog, editors, api, entities} = this.services;
        const info = this.info;
        const path = info.path;
        const context: PropertyContext = {
            path,
            value: info.value,
            slot: {get: () => session.valueAt(path), set: (value) => session.set(path, value, this)},
            writable: info.writable,
            items: info.value.kind === 'collection' ? session.itemsAt(path, this) ?? null : null,
            catalog,
            api,
            entities,
            replace: (value) => session.replace(path, value, this),
            // A Collection's Embed item is its own row: its fields are traversed, "wheels.1.size", never embedded.
            fieldsOf: (object, join) => this.services.fields(path, object, info.field === null ? PropertyPath.TRAVERSE : join),
            itemOf: (index) => this.services.row(PropertyPath.child(path, PropertyPath.TRAVERSE, index)),
            labelOf: (reference) => entities.labelOf(reference),
        };
        const holderChain = info.holderType === null ? [] : [info.holderType, ...catalog.ancestorsOf(info.holderType).map((ancestor) => ancestor.identifier)];
        const Editor = editors.resolve(holderChain, info.field, info.value);
        if (Editor === undefined) {
            return {widget: Elements.create('span', {classes: ['muted'], text: `No editor for a ${info.value.kind}.`}), children: null};
        }

        return new Editor(context);
    }

    /** The chevron collapsing and expanding its children, remembered by its path. */
    private toggle(): HTMLElement {
        const toggle = Elements.create('button', {
            classes: ['property__toggle'],
            attributes: {type: 'button', 'aria-label': 'Collapse or expand', 'aria-expanded': String(!this.services.collapse.isCollapsed(this.info.path))},
        }, [ActionIcon.svg('expand')]);
        toggle.addEventListener('click', () => {
            const collapsed = this.element.classList.toggle('is-collapsed');
            toggle.setAttribute('aria-expanded', String(!collapsed));
            this.services.collapse.setCollapsed(this.info.path, collapsed);
        });

        return toggle;
    }

    /** Whether it's an item in a new item: its Collection is new itself. */
    private isInNewItem(): boolean {
        const path = this.info.path;

        return this.services.session.statusAt(path.slice(0, path.lastIndexOf(PropertyPath.TRAVERSE))).isNew;
    }

    /** What a row of `info` is named: its field, or an item's index. */
    private static labelOf(info: PropertyInfo): string {
        const segments = PropertyPath.segments(info.path);

        return info.field ?? `[${segments[segments.length - 1]}]`;
    }
}
