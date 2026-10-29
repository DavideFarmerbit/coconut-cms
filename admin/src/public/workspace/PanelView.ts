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
}

/** A PanelView class: what a tool or a tab type registers, built with its context. */
export type PanelViewClass = new (context: ViewContext) => PanelView;
