import type {Layout} from './Layout.ts';

/** Told every time the layout changes. */
export interface LayoutListener {
    /** The layout went from `previous` to `next`. */
    layoutChanged(previous: Layout, next: Layout): void;
}
