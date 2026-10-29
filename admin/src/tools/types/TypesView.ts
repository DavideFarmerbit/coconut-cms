import {Elements} from '../../dom/Elements.ts';
import {TypeCatalog} from '../../public/types/TypeCatalog.ts';
import type {TypeDescription} from '../../public/types/TypeDescription.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import {TypeLink} from './TypeLink.ts';

/**
 * The Types panel: every entity type the user may read, in a collapsible
 * group per namespace, each by its key. Opening one marks it. Which type is
 * marked and which groups are closed are remembered.
 */
export class TypesView implements PanelView {
    /** The view state's name for the type marked. */
    private static readonly SELECTED = 'selected';

    /** The view state's name for the namespaces closed. */
    private static readonly COLLAPSED = 'collapsed';

    public readonly element: HTMLElement;

    public constructor(private readonly context: ViewContext) {
        this.element = Elements.create('div', {classes: ['types-view']}, [Elements.create('p', {classes: ['list__note', 'muted'], text: 'Loading…'})]);
        void this.load();
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
    }

    /** Lists the types of the catalog. */
    private async load(): Promise<void> {
        try {
            const groups = (await this.context.typeCatalog()).byNamespace();
            this.element.replaceChildren(...[...groups.entries()].map(([namespace, types]) => this.group(namespace, types)));
            this.markSelected();
        } catch (failure) {
            this.element.replaceChildren(Elements.create('p', {classes: ['list__note', 'muted'], text: failure instanceof Error ? failure.message : String(failure)}));
        }
    }

    /** The collapsible group of `namespace`'s `types`. */
    private group(namespace: string, types: readonly TypeDescription[]): HTMLElement {
        const group = Elements.create('details', {classes: ['type-group']}, [
            Elements.create('summary', {classes: ['type-group__name']}, [
                Elements.create('span', {text: namespace}),
                Elements.create('span', {classes: ['type-group__count'], text: String(types.length)}),
            ]),
            Elements.create('ul', {classes: ['list'], attributes: {role: 'listbox', 'aria-label': namespace}}, types.map((type) => this.item(type.identifier))),
        ]);
        group.open = !this.collapsed().includes(namespace);
        group.addEventListener('toggle', () => {
            const others = this.collapsed().filter((each) => each !== namespace);
            this.context.state.set(TypesView.COLLAPSED, group.open ? others : [...others, namespace]);
        });

        return group;
    }

    /** The list's entry for the type `identifier`, by its key. */
    private item(identifier: string): HTMLElement {
        const item = Elements.create('li', {
            classes: ['list__item'],
            text: TypeCatalog.keyOf(identifier),
            attributes: {role: 'option', tabindex: '0', title: identifier, 'data-type': identifier},
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

    /** The namespaces closed, as remembered. */
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
}
