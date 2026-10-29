import {Elements} from '../../dom/Elements.ts';
import type {ToolId} from '../../public/workspace/Ids.ts';
import type {Section, Side} from '../../public/workspace/Placement.ts';
import type {Registry} from '../../public/workspace/Registry.ts';
import type {ToolDefinition} from '../../public/workspace/ToolDefinition.ts';
import type {Layout} from '../model/Layout.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';

/**
 * One side's stripe: its tools' icons in three sections, two at its top,
 * one at its bottom. Clicking an icon opens or closes its tool's panel.
 */
export class Stripe {
    public readonly element: HTMLElement;
    private readonly sections: Readonly<Record<Section, HTMLElement>>;
    private readonly separator: HTMLElement;

    public constructor(
        private readonly side: Side,
        private readonly tools: Registry<ToolId, ToolDefinition>,
        private readonly store: LayoutStore,
    ) {
        this.sections = {
            topTop: Elements.create('div', {classes: ['stripe__section']}),
            topBottom: Elements.create('div', {classes: ['stripe__section']}),
            bottom: Elements.create('div', {classes: ['stripe__section', 'stripe__section--bottom']}),
        };
        this.separator = Elements.create('hr', {classes: ['stripe__separator']});
        this.element = Elements.create('nav', {classes: ['stripe', `stripe--${side}`], attributes: {'aria-label': `${side} tools`}}, [
            this.sections.topTop,
            this.separator,
            this.sections.topBottom,
            this.sections.bottom,
        ]);
    }

    /** Shows `layout`'s icons, the open ones marked. */
    public render(layout: Layout): void {
        // The separator parts the two top sections: with neither holding an icon, there's nothing to part.
        this.separator.hidden = layout.toolsIn(this.side, 'topTop').length === 0 && layout.toolsIn(this.side, 'topBottom').length === 0;
        for (const [section, element] of Object.entries(this.sections) as [Section, HTMLElement][]) {
            element.replaceChildren(...layout.toolsIn(this.side, section).flatMap((id) => {
                const tool = this.tools.get(id);

                return tool === undefined ? [] : [this.button(tool, layout.isOpen(id))];
            }));
        }
    }

    /** The icon button of `tool`. */
    private button(tool: ToolDefinition, open: boolean): HTMLButtonElement {
        const button = Elements.create('button', {
            classes: ['stripe__button', ...(open ? ['is-open'] : [])],
            attributes: {type: 'button', title: tool.title, 'aria-label': tool.title, 'aria-pressed': String(open)},
        });
        // Trusted markup: a tool is code registered by the admin or a plugin.
        button.innerHTML = tool.icon;
        button.addEventListener('click', () => this.store.update(this.store.current.toggleTool(tool.id)));

        return button;
    }
}
