import type {EditHistory} from '../edits/EditHistory.ts';
import type {ViewContext} from './ViewContext.ts';

/**
 * What a tool's panel or a tab shows: a class the shell builds the first
 * time it opens, then keeps alive. Hidden or moved, its element and its own
 * state survive; it's disposed only when its tab closes.
 */
export interface PanelView {
    /** Its root element, built once. */
    readonly element: HTMLElement;

    /** Called each time it's shown: to resume work, or focus. */
    shown(): void;

    /** Called each time it's hidden: to pause work. */
    hidden(): void;

    /** Called once, when it's gone for good: to stop what it started. */
    dispose(): void;

    /**
     * Asked before its tab closes: false keeps it open. A view with unsaved
     * changes asks whether to save them. Without it, a tab always closes.
     */
    canClose?(): Promise<boolean>;

    /**
     * The history of what it's editing where the focus is: an entity's
     * session, the schema draft, a plugin's own model, or the view's own
     * state. The shell sends it Cmd or Ctrl and Z, and Shift with them,
     * while the focus is inside it. A view editing several things gives the
     * history of the one focused; without it, there's nothing to undo here.
     */
    readonly history?: EditHistory;
}

/** A PanelView class: what a tool or a tab type registers, built with its context. */
export type PanelViewClass = new (context: ViewContext) => PanelView;
