import {Elements} from '../../dom/Elements.ts';
import {Tooltips} from '../../dom/Tooltips.ts';
import type {TabTypeId} from '../../public/workspace/Ids.ts';
import type {Registry} from '../../public/workspace/Registry.ts';
import type {TabTypeDefinition} from '../../public/workspace/TabTypeDefinition.ts';
import {Layout} from '../model/Layout.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';
import type {TabData} from '../model/TabData.ts';
import {MissingView} from './MissingView.ts';
import type {ViewContexts} from './ViewContexts.ts';
import type {ViewHost} from './ViewHost.ts';

/**
 * The canvas: an island with a tab bar over the tab shown. Every open tab's
 * view is kept alive, hidden, until its tab closes.
 */
export class TabGroup {
    public readonly element: HTMLElement;
    private readonly bar: HTMLElement;
    private readonly content: HTMLElement;
    private readonly empty: HTMLElement;

    public constructor(
        private readonly tabTypes: Registry<TabTypeId, TabTypeDefinition>,
        private readonly store: LayoutStore,
        private readonly host: ViewHost,
        private readonly contexts: ViewContexts,
    ) {
        this.bar = Elements.create('div', {classes: ['tab-bar'], attributes: {role: 'tablist'}});
        this.empty = Elements.create('div', {classes: ['view-message']}, [Elements.create('p', {classes: ['muted'], text: 'Nothing open. Open something from a tool on the side.'})]);
        this.content = Elements.create('div', {classes: ['tab-group__content']});
        this.element = Elements.create('section', {classes: ['tab-group', 'island']}, [this.bar, this.content]);
    }

    /** Shows `layout`'s tabs and the one shown; disposes of the views of tabs closed. */
    public render(layout: Layout): void {
        this.bar.replaceChildren(...layout.tabs.map((tab) => this.tabButton(tab, tab.id === layout.activeTab)));

        const open = new Set(layout.tabs.map((tab) => Layout.tabKey(tab.id)));
        for (const key of this.host.keys()) {
            if (key.startsWith('tab:') && !open.has(key)) {
                this.host.dispose(key);
            }
        }

        for (const tab of layout.tabs) {
            if (tab.id !== layout.activeTab) {
                this.host.hide(Layout.tabKey(tab.id));
            }
        }

        const active = layout.tabs.find((tab) => tab.id === layout.activeTab);
        this.empty.remove();
        if (active === undefined) {
            this.content.append(this.empty);

            return;
        }

        const key = Layout.tabKey(active.id);
        this.host.show(key, this.content, () => {
            const type = this.tabTypes.get(active.type);
            if (type === undefined) {
                return new MissingView(`No tab type "${active.type}" is registered.`);
            }

            try {
                return new type.view(this.contexts.create(key, active.params));
            } catch (failure) {
                return new MissingView(`This tab failed to open: ${String(failure)}`);
            }
        });
    }

    /** The bar's button for `tab`, with its close button. */
    private tabButton(tab: TabData, shown: boolean): HTMLElement {
        const close = Elements.create('button', {classes: ['tab__close'], text: '×', attributes: {type: 'button', [Tooltips.ATTRIBUTE]: 'Close', 'aria-label': `Close ${tab.title}`}});
        close.addEventListener('click', (event) => {
            event.stopPropagation();
            this.store.update(this.store.current.closeTab(tab.id));
        });

        const button = Elements.create('div', {
            classes: ['tab', ...(shown ? ['is-active'] : [])],
            attributes: {role: 'tab', 'aria-selected': String(shown), tabindex: '0', [Tooltips.ATTRIBUTE]: tab.title},
        }, [Elements.create('span', {classes: ['tab__title'], text: tab.title}), close]);
        button.addEventListener('click', () => this.store.update(this.store.current.activateTab(tab.id)));
        button.addEventListener('auxclick', (event) => {
            if (event.button === 1) {
                this.store.update(this.store.current.closeTab(tab.id));
            }
        });

        return button;
    }
}
