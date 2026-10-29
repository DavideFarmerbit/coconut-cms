/** What a drag starts from, and how far it may go. */
export interface DragRange {
    readonly value: number;
    readonly min: number;
    readonly max: number;
}

/**
 * What a Splitter resizes: a container's edge, or the divider between its two
 * panes. Values are pixels along the splitter's axis.
 */
export interface SplitterTarget {
    /** Which way it drags: "x" across, "y" up and down. */
    readonly axis: 'x' | 'y';
    /** 1 when moving the pointer right or down grows the value; -1 when it shrinks it. */
    readonly direction: 1 | -1;

    /** Called as a drag starts: where it starts from, and its bounds. */
    begin(): DragRange;

    /** Called on each move, with the value clamped: show it, without changing the layout. */
    move(value: number): void;

    /** Called once the drag ends, with the value it ended at: change the layout. */
    commit(value: number): void;

    /** Called when the drag is cancelled: show the layout as it is again. */
    cancel(): void;
}
