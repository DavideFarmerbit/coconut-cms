import {Elements} from '../../dom/Elements.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import type {TypeList} from './TypeDescription.ts';
import {TypesTool} from './TypesTool.ts';

/**
 * The Types panel: every entity type the user may read, by identifier.
 * Clicking one opens its tab and marks it, which is remembered.
 */
export class TypesView implements PanelView {
    /** The view state's name for the type marked. */
    private static readonly SELECTED = 'selected';

    public readonly element: HTMLElement;
    private readonly list: HTMLElement;

    public constructor(private readonly context: ViewContext) {
        this.list = Elements.create('ul', {classes: ['list'], attributes: {role: 'listbox', 'aria-label': 'Types'}});
        this.element = Elements.create('div', {classes: ['types-view']}, [this.list]);
        void this.load();
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
    }

    /** Lists the types the backend gives. */
    private async load(): Promise<void> {
        this.list.replaceChildren(Elements.create('li', {classes: ['list__note', 'muted'], text: 'Loading…'}));
        try {
            const {types} = await this.context.api.call<TypeList>('coconut-cms:types.list');
            this.list.replaceChildren(...types.map((type) => this.item(type.identifier)));
            this.markSelected();
        } catch (failure) {
            this.list.replaceChildren(Elements.create('li', {classes: ['list__note', 'muted'], text: failure instanceof Error ? failure.message : String(failure)}));
        }
    }

    /** The list's entry for the type `identifier`. */
    private item(identifier: string): HTMLElement {
        const item = Elements.create('li', {classes: ['list__item'], text: identifier, attributes: {role: 'option', tabindex: '0', 'data-type': identifier}});
        const open = (): void => {
            this.context.state.set(TypesView.SELECTED, identifier);
            this.markSelected();
            this.context.workspace.openTab(TypesTool.TAB, identifier, {type: identifier}, identifier);
        };
        item.addEventListener('click', open);
        item.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                open();
            }
        });

        return item;
    }

    /** Marks the type saved as selected. */
    private markSelected(): void {
        const selected = this.context.state.get(TypesView.SELECTED);
        for (const item of this.list.querySelectorAll<HTMLElement>('[data-type]')) {
            const isSelected = item.dataset['type'] === selected;
            item.classList.toggle('is-selected', isSelected);
            item.setAttribute('aria-selected', String(isSelected));
        }
    }
}
