import {Elements} from '../../dom/Elements.ts';
import {TypeCatalog} from '../../public/types/TypeCatalog.ts';
import type {TypeDescription} from '../../public/types/TypeDescription.ts';
import {TypeStore} from '../../public/types/TypeStore.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import {TypeLink} from './TypeLink.ts';

/** How the panel groups the types: by namespace, or each under the type it extends. */
type Grouping = 'namespace' | 'hierarchy';

/**
 * The Types panel: every entity type the user may read, as saved, read
 * only: what's there. Grouped by namespace, each by its key, or by
 * hierarchy, each under the type it extends. Opening one marks it. The
 * grouping, the type marked and the groups closed are remembered. It shows
 * the types again when they change.
 */
export class TypesView implements PanelView {
    /** The view state's name for the type marked. */
    private static readonly SELECTED = 'selected';

    /** The view state's name for the namespaces closed. */
    private static readonly COLLAPSED = 'collapsed';

    /** The view state's name for the grouping. */
    private static readonly GROUPING = 'grouping';

    public readonly element: HTMLElement;
    private readonly types: TypeStore;
    private readonly listener = (): void => this.show();

    public constructor(private readonly context: ViewContext) {
        this.element = Elements.create('div', {classes: ['types-view']}, [TypesView.note('Loading…')]);
        this.types = context.service(TypeStore);
        this.types.ready.then(() => {
            this.show();
            this.types.onChange(this.listener);
        }, (failure: unknown) => {
            this.element.replaceChildren(TypesView.note(failure instanceof Error ? failure.message : String(failure)));
        });
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
        this.types.offChange(this.listener);
    }

    /** Lists the types of the catalog now, grouped as remembered. */
    private show(): void {
        const catalog = this.types.catalog;
        if (this.grouping() === 'namespace') {
            this.element.replaceChildren(this.toolbar(), ...[...catalog.byNamespace().entries()].map(([namespace, types]) => this.group(namespace, types)));
        } else {
            const roots = catalog.all().filter((type) => type.parent === null || catalog.get(type.parent) === undefined);
            this.element.replaceChildren(this.toolbar(), Elements.create('ul', {classes: ['list', 'type-tree'], attributes: {role: 'listbox', 'aria-label': 'Types'}}, roots.flatMap((root) => this.branch(catalog, root, 0))));
        }
        this.markSelected();
    }

    /** The switch between the groupings. */
    private toolbar(): HTMLElement {
        const option = (grouping: Grouping, label: string): HTMLElement => {
            const button = Elements.create('button', {classes: ['button', 'button--quiet', ...(this.grouping() === grouping ? ['is-selected'] : [])], text: label, attributes: {type: 'button', 'aria-pressed': String(this.grouping() === grouping)}});
            button.addEventListener('click', () => {
                this.context.state.set(TypesView.GROUPING, grouping);
                this.show();
            });

            return button;
        };

        return Elements.create('div', {classes: ['types-view__toolbar'], attributes: {role: 'group', 'aria-label': 'Group by'}}, [option('namespace', 'By namespace'), option('hierarchy', 'By hierarchy')]);
    }

    /** `type`'s entry at `depth`, then every type extending it, one deeper each, by full identifier. */
    private branch(catalog: TypeCatalog, type: TypeDescription, depth: number): HTMLElement[] {
        const item = this.item(type.identifier, type.identifier);
        item.style.setProperty('--depth', String(depth));

        return [item, ...catalog.subtypesOf(type.identifier).flatMap((subtype) => this.branch(catalog, subtype, depth + 1))];
    }

    /** The collapsible group of `namespace`'s `types`, by key. */
    private group(namespace: string, types: readonly TypeDescription[]): HTMLElement {
        const group = Elements.create('details', {classes: ['type-group']}, [
            Elements.create('summary', {classes: ['type-group__name']}, [
                Elements.create('span', {text: namespace}),
                Elements.create('span', {classes: ['type-group__count'], text: String(types.length)}),
            ]),
            Elements.create('ul', {classes: ['list'], attributes: {role: 'listbox', 'aria-label': namespace}}, types.map((type) => this.item(type.identifier, TypeCatalog.keyOf(type.identifier)))),
        ]);
        group.open = !this.collapsed().includes(namespace);
        group.addEventListener('toggle', () => {
            const others = this.collapsed().filter((each) => each !== namespace);
            this.context.state.set(TypesView.COLLAPSED, group.open ? others : [...others, namespace]);
        });

        return group;
    }

    /** The list's entry opening the type `identifier`, reading `label`. */
    private item(identifier: string, label: string): HTMLElement {
        const item = Elements.create('li', {
            classes: ['list__item'],
            text: label,
            attributes: {role: 'option', tabindex: '0', 'data-type': identifier},
        });
        TypeLink.attach(item, identifier, this.context.workspace, (opened) => {
            this.context.state.set(TypesView.SELECTED, opened);
            this.markSelected();
        });
        item.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                item.click();
            }
        });

        return item;
    }

    /** The grouping remembered: by namespace unless set. */
    private grouping(): Grouping {
        return this.context.state.get(TypesView.GROUPING) === 'hierarchy' ? 'hierarchy' : 'namespace';
    }

    /** The groups closed, as remembered. */
    private collapsed(): readonly string[] {
        const collapsed = this.context.state.get(TypesView.COLLAPSED);

        return Array.isArray(collapsed) ? collapsed.filter((each): each is string => typeof each === 'string') : [];
    }

    /** Marks the type remembered as selected. */
    private markSelected(): void {
        const selected = this.context.state.get(TypesView.SELECTED);
        for (const item of this.element.querySelectorAll<HTMLElement>('[data-type]')) {
            const isSelected = item.dataset['type'] === selected;
            item.classList.toggle('is-selected', isSelected);
            item.setAttribute('aria-selected', String(isSelected));
        }
    }

    /** A muted line in the panel: why it's empty. */
    private static note(text: string): HTMLElement {
        return Elements.create('p', {classes: ['list__note', 'muted'], text});
    }
}
