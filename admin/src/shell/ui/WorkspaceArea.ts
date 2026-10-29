import {Elements} from '../../dom/Elements.ts';
import type {TabTypeId, ToolId} from '../../public/workspace/Ids.ts';
import type {Registry} from '../../public/workspace/Registry.ts';
import type {TabTypeDefinition} from '../../public/workspace/TabTypeDefinition.ts';
import type {ToolDefinition} from '../../public/workspace/ToolDefinition.ts';
import {type Container, CONTAINERS} from '../model/Container.ts';
import type {Layout} from '../model/Layout.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';
import type {Area} from '../model/SizeResolver.ts';
import {SizeResolver} from '../model/SizeResolver.ts';
import {ContainerEdge} from './ContainerEdge.ts';
import {PanelContainer} from './PanelContainer.ts';
import {TabGroup} from './TabGroup.ts';
import type {ViewContexts} from './ViewContexts.ts';
import type {ViewHost} from './ViewHost.ts';

/**
 * The space between the stripes: the three containers around the canvas.
 * Its size is watched; on every change of it or of the layout, the resolver
 * gives each container its size, written as CSS properties the grid reads.
 */
export class WorkspaceArea {
    public readonly element: HTMLElement;
    private readonly containers: Readonly<Record<Container, PanelContainer>>;
    private readonly tabs: TabGroup;
    private readonly resolver = new SizeResolver();
    private measured: Area = {width: 0, height: 0};

    public constructor(
        tools: Registry<ToolId, ToolDefinition>,
        tabTypes: Registry<TabTypeId, TabTypeDefinition>,
        private readonly store: LayoutStore,
        host: ViewHost,
        contexts: ViewContexts,
    ) {
        const container = (which: Container): PanelContainer => new PanelContainer(which, tools, store, host, contexts, new ContainerEdge(which, store, this.resolver, this));
        this.containers = {left: container('left'), right: container('right'), bottom: container('bottom')};
        this.tabs = new TabGroup(tabTypes, store, host, contexts);
        this.element = Elements.create('div', {classes: ['workspace']}, [
            this.containers.left.element,
            this.tabs.element,
            this.containers.right.element,
            this.containers.bottom.element,
        ]);

        new ResizeObserver(([entry]) => {
            if (entry !== undefined) {
                this.measured = {width: entry.contentRect.width, height: entry.contentRect.height};
                this.showSizes(this.store.current);
            }
        }).observe(this.element);
    }

    /** Shows `layout`: its panels, its tabs, its sizes. */
    public render(layout: Layout): void {
        for (const which of CONTAINERS) {
            this.containers[which].render(layout);
        }

        this.tabs.render(layout);
        this.showSizes(layout);
    }

    /** The workspace's size, as last measured. */
    public area(): Area {
        return this.measured;
    }

    /** Shows `container` at `pixels`, without changing the layout: during a drag. */
    public showSize(container: Container, pixels: number): void {
        const property = {left: '--cc-left-w', right: '--cc-right-w', bottom: '--cc-bottom-h'}[container];
        // An open container takes its size plus the gap between it and the canvas.
        this.element.style.setProperty(property, pixels > 0 ? `calc(${Math.round(pixels)}px + var(--cc-island-gap))` : '0px');
    }

    /** Shows every container at the size the resolver gives it for `layout`. */
    private showSizes(layout: Layout): void {
        const sizes = this.resolver.resolve(layout, this.measured);
        for (const which of CONTAINERS) {
            this.showSize(which, sizes[which]);
        }
    }
}
