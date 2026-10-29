import type {TabTypeId} from './Ids.ts';
import type {PanelViewClass} from './PanelView.ts';

/** A kind of tab the canvas opens: Workspace.openTab() names it. Registered through admin.tabTypes. */
export interface TabTypeDefinition {
    readonly id: TabTypeId;
    /** What each of its tabs shows, built with the tab's parameters. */
    readonly view: PanelViewClass;
}
