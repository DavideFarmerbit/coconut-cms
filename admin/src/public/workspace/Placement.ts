/** The stripes holding tool icons: on the left and right edges of the frame. */
export const SIDES = ['left', 'right'] as const;

/** One of SIDES. */
export type Side = typeof SIDES[number];

/**
 * The sections of a stripe, top to bottom: two at its top, one at its
 * bottom. Each holds at most one open tool.
 */
export const SECTIONS = ['topTop', 'topBottom', 'bottom'] as const;

/** One of SECTIONS. */
export type Section = typeof SECTIONS[number];

/** Where a tool's icon sits: its stripe and its section. Where its panel opens follows from it. */
export interface Placement {
    readonly side: Side;
    readonly section: Section;
}
