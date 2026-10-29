import type {TabId, TabTypeId, ToolId} from '../../public/workspace/Ids.ts';
import type {JsonValue} from '../../public/workspace/Json.ts';
import {type Placement, type Section, SECTIONS, type Side, SIDES} from '../../public/workspace/Placement.ts';
import type {TabOpening} from '../../public/workspace/TabOpening.ts';
import {type Container, CONTAINERS} from './Container.ts';
import type {LayoutData} from './LayoutData.ts';
import {Slots} from './Slots.ts';
import type {TabData} from './TabData.ts';

/** A tool as the layout needs to know it: where its icon goes by default. */
export interface PlacedTool {
    readonly id: ToolId;
    readonly placement: Placement;
}

/**
 * The whole workspace's state, the one source of truth: icons, open panels,
 * sizes, tabs, and each view's saved state. Immutable: every change returns
 * a new Layout, which LayoutStore takes. Pure: no DOM, no clock, no ids made
 * here.
 */
export class Layout {
    /** The serialized form's version. */
    public static readonly VERSION = 2;

    /** A new container's preferred size, in pixels. */
    public static readonly DEFAULT_SIZES: Readonly<Record<Container, number>> = {left: 280, right: 320, bottom: 240};

    private constructor(private readonly data: LayoutData) {
    }

    /** A layout with nothing placed or open. */
    public static initial(): Layout {
        const sections = <T>(value: () => T): Record<Section, T> => ({topTop: value(), topBottom: value(), bottom: value()});

        return new Layout({
            version: Layout.VERSION,
            stripes: {left: sections(() => []), right: sections(() => [])},
            active: {left: sections(() => null), right: sections(() => null)},
            sizes: {...Layout.DEFAULT_SIZES},
            splits: {left: 0.5, right: 0.5, bottom: 0.5},
            tabs: [],
            activeTab: null,
            recentTabs: [],
            viewState: {},
        });
    }

    /**
     * The layout `data` holds, as saved; null when it isn't one of this
     * version. What it names is checked by reconciled(), not here.
     */
    public static fromData(data: unknown): Layout | null {
        if (typeof data !== 'object' || data === null || (data as Partial<LayoutData>).version !== Layout.VERSION) {
            return null;
        }

        const keys: readonly (keyof LayoutData)[] = ['stripes', 'active', 'sizes', 'splits', 'tabs', 'activeTab', 'recentTabs', 'viewState'];

        return keys.every((key) => key in data) ? new Layout(data as LayoutData) : null;
    }

    /** The key a tool's view keeps its state under. */
    public static toolKey(id: ToolId): string {
        return `tool:${id}`;
    }

    /** The key a tab's view keeps its state under. */
    public static tabKey(id: TabId): string {
        return `tab:${id}`;
    }

    /** Its serialized form. */
    public toData(): LayoutData {
        return this.data;
    }

    /** The icons of `side`'s `section`, in order. */
    public toolsIn(side: Side, section: Section): readonly ToolId[] {
        return this.data.stripes[side][section];
    }

    /** Where `tool`'s icon sits; null when it isn't placed. */
    public placementOf(tool: ToolId): Placement | null {
        for (const side of SIDES) {
            for (const section of SECTIONS) {
                if (this.data.stripes[side][section].includes(tool)) {
                    return {side, section};
                }
            }
        }

        return null;
    }

    /** The tool open at `placement`; null for none. */
    public openAt(placement: Placement): ToolId | null {
        return this.data.active[placement.side][placement.section];
    }

    /** Whether `tool`'s panel is open. */
    public isOpen(tool: ToolId): boolean {
        const placement = this.placementOf(tool);

        return placement !== null && this.openAt(placement) === tool;
    }

    /** Whether any panel is open in `container`. */
    public isContainerOpen(container: Container): boolean {
        return Slots.placementsIn(container).some((placement) => this.openAt(placement) !== null);
    }

    /** `container`'s preferred size, in pixels. */
    public sizeOf(container: Container): number {
        return this.data.sizes[container];
    }

    /** `container`'s share given to its first pane. */
    public splitOf(container: Container): number {
        return this.data.splits[container];
    }

    /** The canvas's tabs, in order. */
    public get tabs(): readonly TabData[] {
        return this.data.tabs;
    }

    /** The tab shown; null for none. */
    public get activeTab(): TabId | null {
        return this.data.activeTab;
    }

    /** What the view under `key` saved. */
    public stateOf(key: string): Readonly<Record<string, JsonValue>> {
        return this.data.viewState[key] ?? {};
    }

    /** Opens `tool`'s panel, closing the one open in its slot; closes it when it's open. Nothing for a tool not placed. */
    public toggleTool(tool: ToolId): Layout {
        const placement = this.placementOf(tool);
        if (placement === null) {
            return this;
        }

        return this.withActive(placement, this.openAt(placement) === tool ? null : tool);
    }

    /** Closes `tool`'s panel; nothing when it isn't open. */
    public closeTool(tool: ToolId): Layout {
        const placement = this.placementOf(tool);

        return placement === null || this.openAt(placement) !== tool ? this : this.withActive(placement, null);
    }

    /**
     * Shows a tab of `type`: the one open with `key` already, or one opened
     * as `opening` says. Reusing replaces the tab of `type` shown most
     * recently, in place, forgetting its view's state; with none, or with
     * "new", the tab is added after the others.
     * @param id The new tab's id, made by the caller: the layout stays pure.
     * @param keep Tabs a reuse never replaces: those with unsaved work.
     */
    public openTab(id: TabId, type: TabTypeId, title: string, params: JsonValue, key: string, opening: TabOpening = 'new', keep: ReadonlySet<TabId> = new Set()): Layout {
        const open = this.data.tabs.find((tab) => tab.type === type && tab.key === key);
        if (open !== undefined) {
            return this.activateTab(open.id);
        }

        const tab: TabData = {id, type, title, params, key};
        const reused = opening === 'reuse'
            ? this.data.recentTabs.find((recent) => !keep.has(recent) && this.data.tabs.some((each) => each.id === recent && each.type === type))
            : undefined;
        if (reused === undefined) {
            return this.with({tabs: [...this.data.tabs, tab], activeTab: id, recentTabs: [id, ...this.data.recentTabs]});
        }

        const {[Layout.tabKey(reused)]: _forgotten, ...viewState} = this.data.viewState;

        return this.with({
            tabs: this.data.tabs.map((each) => each.id === reused ? tab : each),
            activeTab: id,
            recentTabs: [id, ...this.data.recentTabs.filter((recent) => recent !== reused)],
            viewState,
        });
    }

    /** The tab `id` renamed `title`; nothing for a tab not open, or one already so named. */
    public retitleTab(id: TabId, title: string): Layout {
        const tab = this.data.tabs.find((each) => each.id === id);
        if (tab === undefined || tab.title === title) {
            return this;
        }

        return this.with({tabs: this.data.tabs.map((each) => each.id === id ? {...each, title} : each)});
    }

    /** Shows the tab `id`; nothing for a tab not open. */
    public activateTab(id: TabId): Layout {
        return this.data.tabs.some((tab) => tab.id === id)
            ? this.with({activeTab: id, recentTabs: [id, ...this.data.recentTabs.filter((recent) => recent !== id)]})
            : this;
    }

    /** Closes the tab `id`, forgetting its view's state. The tab after it is shown, or the one before. */
    public closeTab(id: TabId): Layout {
        const index = this.data.tabs.findIndex((tab) => tab.id === id);
        if (index < 0) {
            return this;
        }

        const tabs = this.data.tabs.filter((tab) => tab.id !== id);
        const activeTab = this.data.activeTab !== id ? this.data.activeTab : (tabs[index] ?? tabs[index - 1])?.id ?? null;
        const recentTabs = this.data.recentTabs.filter((recent) => recent !== id);
        const {[Layout.tabKey(id)]: _forgotten, ...viewState} = this.data.viewState;
        const closed = this.with({tabs, recentTabs, viewState});

        return activeTab === null ? closed.with({activeTab: null}) : closed.activateTab(activeTab);
    }

    /** `container` with a preferred size of `pixels`. */
    public resized(container: Container, pixels: number): Layout {
        return this.with({sizes: {...this.data.sizes, [container]: Math.max(0, Math.round(pixels))}});
    }

    /** `container` with its first pane given `ratio` of it, kept from 0 to 1. */
    public split(container: Container, ratio: number): Layout {
        return this.with({splits: {...this.data.splits, [container]: Math.min(1, Math.max(0, ratio))}});
    }

    /** The view under `key` with `name` set to `value` in its state. */
    public withViewState(key: string, name: string, value: JsonValue): Layout {
        return this.with({viewState: {...this.data.viewState, [key]: {...this.stateOf(key), [name]: value}}});
    }

    /**
     * This layout made to fit what's registered now: a tool no longer
     * registered is dropped, a new one is placed where it asks, a tab of a
     * type no longer registered is closed, and state no view owns is dropped.
     */
    public reconciled(tools: readonly PlacedTool[], tabTypes: ReadonlySet<TabTypeId>): Layout {
        const registered = new Set(tools.map((tool) => tool.id));
        const stripes = {left: {...this.data.stripes.left}, right: {...this.data.stripes.right}};
        const active = {left: {...this.data.active.left}, right: {...this.data.active.right}};
        for (const side of SIDES) {
            for (const section of SECTIONS) {
                stripes[side][section] = stripes[side][section].filter((tool) => registered.has(tool));
                const open = active[side][section];
                if (open !== null && !stripes[side][section].includes(open)) {
                    active[side][section] = null;
                }
            }
        }

        let layout = this.with({stripes, active});
        for (const tool of tools) {
            if (layout.placementOf(tool.id) === null) {
                const {side, section} = tool.placement;
                const placed = {...layout.data.stripes[side], [section]: [...layout.data.stripes[side][section], tool.id]};
                layout = layout.with({stripes: {...layout.data.stripes, [side]: placed}});
            }
        }

        const tabs = layout.data.tabs.filter((tab) => tabTypes.has(tab.type));
        const openIds = new Set(tabs.map((tab) => tab.id));
        const activeTab = layout.data.activeTab !== null && openIds.has(layout.data.activeTab) ? layout.data.activeTab : tabs[0]?.id ?? null;
        const recentTabs = layout.data.recentTabs.filter((recent) => openIds.has(recent));
        const owners = new Set([...tools.map((tool) => Layout.toolKey(tool.id)), ...tabs.map((tab) => Layout.tabKey(tab.id))]);
        const viewState = Object.fromEntries(Object.entries(layout.data.viewState).filter(([key]) => owners.has(key)));

        return layout.with({tabs, activeTab, recentTabs, viewState});
    }

    /** Every container, with whether it holds an open panel. */
    public openContainers(): Readonly<Record<Container, boolean>> {
        return Object.fromEntries(CONTAINERS.map((container) => [container, this.isContainerOpen(container)])) as Record<Container, boolean>;
    }

    /** This layout with `placement`'s open tool set to `tool`. */
    private withActive(placement: Placement, tool: ToolId | null): Layout {
        const side = {...this.data.active[placement.side], [placement.section]: tool};

        return this.with({active: {...this.data.active, [placement.side]: side}});
    }

    /** This layout with `changes` applied. */
    private with(changes: Partial<LayoutData>): Layout {
        return new Layout({...this.data, ...changes});
    }
}
