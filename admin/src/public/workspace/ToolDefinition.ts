import type {ToolId} from './Ids.ts';
import type {PanelViewClass} from './PanelView.ts';
import type {Placement} from './Placement.ts';

/** A tool: an icon on a stripe, opening a panel. Registered through admin.tools. */
export interface ToolDefinition {
    readonly id: ToolId;
    readonly title: string;
    /** Its icon: SVG markup drawing with currentColor, so it follows the theme. */
    readonly icon: string;
    /** Where its icon goes the first time; the user may move it later. */
    readonly placement: Placement;
    /** What its panel shows. */
    readonly view: PanelViewClass;
}
