import {Elements} from '../../dom/Elements.ts';
import type {ToolId} from '../../public/workspace/Ids.ts';
import type {Registry} from '../../public/workspace/Registry.ts';
import type {ToolDefinition} from '../../public/workspace/ToolDefinition.ts';
import type {Container} from '../model/Container.ts';
import type {Layout} from '../model/Layout.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';
import {Slots} from '../model/Slots.ts';
import {PanelFrame} from './PanelFrame.ts';
import {Splitter} from './Splitter.ts';
import type {DragRange, SplitterTarget} from './SplitterTarget.ts';
import type {ViewContexts} from './ViewContexts.ts';
import type {ViewHost} from './ViewHost.ts';

/**
 * One of the three containers: up to two panels, with a divider between
 * them when both are open, and a handle on its canvas side resizing it. It
 * takes no room while no panel is open.
 */
export class PanelContainer implements SplitterTarget {
    /** The least a pane is dragged to, in pixels. */
    public static readonly PANE_MINIMUM = 80;

    public readonly element: HTMLElement;
    public readonly axis: 'x' | 'y';
    public readonly direction = 1;
    private readonly frames: readonly [PanelFrame, PanelFrame];
    private readonly divider: Splitter;
    private readonly edge: Splitter;

    /** @param edgeTarget What the handle on its canvas side resizes: the workspace's sizing of it. */
    public constructor(
        private readonly container: Container,
        tools: Registry<ToolId, ToolDefinition>,
        private readonly store: LayoutStore,
        host: ViewHost,
        contexts: ViewContexts,
        edgeTarget: SplitterTarget,
    ) {
        // Side containers stack their panes; the bottom one sets them side by side.
        this.axis = container === 'bottom' ? 'x' : 'y';
        this.frames = [new PanelFrame(tools, store, host, contexts), new PanelFrame(tools, store, host, contexts)];
        this.divider = new Splitter(this);
        this.edge = new Splitter(edgeTarget);
        this.edge.element.classList.add('splitter--edge');
        this.element = Elements.create('div', {classes: ['panel-container', `panel-container--${container}`]}, [
            Elements.create('div', {classes: ['panel-container__panes']}, [this.frames[0].element, this.divider.element, this.frames[1].element]),
            this.edge.element,
        ]);
    }

    /** Shows the panels `layout` has open here, at its split. */
    public render(layout: Layout): void {
        const [first, second] = Slots.placementsIn(this.container).map((placement) => layout.openAt(placement));
        this.frames[0].render(first ?? null);
        this.frames[1].render(second ?? null);
        this.element.hidden = !layout.isContainerOpen(this.container);
        const both = first !== null && second !== null;
        this.divider.element.hidden = !both;
        if (both) {
            this.showSplit(layout.splitOf(this.container));
        } else {
            // A lone panel takes the whole container: grows summing under 1 would leave room empty.
            this.frames[0].element.style.flexGrow = '1';
            this.frames[1].element.style.flexGrow = '1';
        }
    }

    public begin(): DragRange {
        const total = this.panesLength();

        return {
            value: total * this.store.current.splitOf(this.container),
            min: PanelContainer.PANE_MINIMUM,
            max: Math.max(PanelContainer.PANE_MINIMUM, total - PanelContainer.PANE_MINIMUM),
        };
    }

    public move(value: number): void {
        this.showSplit(value / this.panesLength());
    }

    public commit(value: number): void {
        this.store.update(this.store.current.split(this.container, value / this.panesLength()));
    }

    public cancel(): void {
        this.showSplit(this.store.current.splitOf(this.container));
    }

    /** Gives the first pane `ratio` of the room, the second the rest. */
    private showSplit(ratio: number): void {
        this.frames[0].element.style.flexGrow = String(ratio);
        this.frames[1].element.style.flexGrow = String(1 - ratio);
    }

    /** How long both panes are together, along the axis, in pixels. */
    private panesLength(): number {
        const size = (frame: PanelFrame): number => {
            const rect = frame.element.getBoundingClientRect();

            return this.axis === 'x' ? rect.width : rect.height;
        };

        return Math.max(1, size(this.frames[0]) + size(this.frames[1]));
    }
}
