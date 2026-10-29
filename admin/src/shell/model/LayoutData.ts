import type {TabId, ToolId} from '../../public/workspace/Ids.ts';
import type {JsonValue} from '../../public/workspace/Json.ts';
import type {Section, Side} from '../../public/workspace/Placement.ts';
import type {Container} from './Container.ts';
import type {TabData} from './TabData.ts';

/** The layout's serialized form: what's saved, and what Layout wraps. Plain JSON only. */
export interface LayoutData {
    /** The form's version: a saved layout of another one is dropped for the default. */
    readonly version: number;
    /** Each section's icons, in order. */
    readonly stripes: Readonly<Record<Side, Readonly<Record<Section, readonly ToolId[]>>>>;
    /** Each section's open tool; null for none. */
    readonly active: Readonly<Record<Side, Readonly<Record<Section, ToolId | null>>>>;
    /** Each container's preferred size, in pixels: what the user left it at. */
    readonly sizes: Readonly<Record<Container, number>>;
    /** Each container's share given to its first pane, from 0 to 1. */
    readonly splits: Readonly<Record<Container, number>>;
    /** The canvas's tabs, in order. */
    readonly tabs: readonly TabData[];
    /** The tab shown; null for none. */
    readonly activeTab: TabId | null;
    /** Each view's saved state, by its view key. */
    readonly viewState: Readonly<Record<string, Readonly<Record<string, JsonValue>>>>;
}
