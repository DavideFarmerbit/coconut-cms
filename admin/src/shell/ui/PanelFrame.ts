import {Elements} from '../../dom/Elements.ts';
import type {ToolId} from '../../public/workspace/Ids.ts';
import type {Registry} from '../../public/workspace/Registry.ts';
import type {ToolDefinition} from '../../public/workspace/ToolDefinition.ts';
import {Layout} from '../model/Layout.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';
import {MissingView} from './MissingView.ts';
import type {ViewContexts} from './ViewContexts.ts';
import type {ViewHost} from './ViewHost.ts';

/** One panel: an island with a header, its tool's title and a hide button, over the tool's view. */
export class PanelFrame {
    public readonly element: HTMLElement;
    private readonly title: HTMLElement;
    private readonly body: HTMLElement;
    private shown: ToolId | null = null;

    public constructor(
        private readonly tools: Registry<ToolId, ToolDefinition>,
        private readonly store: LayoutStore,
        private readonly host: ViewHost,
        private readonly contexts: ViewContexts,
    ) {
        this.title = Elements.create('h2', {classes: ['panel__title']});
        const hide = Elements.create('button', {classes: ['icon-button'], text: '−', attributes: {type: 'button', title: 'Hide', 'aria-label': 'Hide'}});
        hide.addEventListener('click', () => {
            if (this.shown !== null) {
                this.store.update(this.store.current.closeTool(this.shown));
            }
        });
        this.body = Elements.create('div', {classes: ['panel__body']});
        this.element = Elements.create('section', {classes: ['panel', 'island']}, [
            Elements.create('header', {classes: ['panel__header']}, [this.title, hide]),
            this.body,
        ]);
        // Empty until render() gives it a tool.
        this.element.hidden = true;
    }

    /** Shows `tool`'s view, or nothing for null; the view shown before is kept alive, hidden. */
    public render(tool: ToolId | null): void {
        if (tool === this.shown) {
            return;
        }

        if (this.shown !== null) {
            this.host.hide(Layout.toolKey(this.shown));
        }

        this.shown = tool;
        this.element.hidden = tool === null;
        if (tool === null) {
            return;
        }

        const definition = this.tools.get(tool);
        this.title.textContent = definition?.title ?? tool;
        const key = Layout.toolKey(tool);
        this.host.show(key, this.body, () => {
            if (definition === undefined) {
                return new MissingView(`No tool "${tool}" is registered.`);
            }

            try {
                return new definition.view(this.contexts.create(key, null));
            } catch (failure) {
                return new MissingView(`${definition.title} failed to open: ${String(failure)}`);
            }
        });
    }
}
