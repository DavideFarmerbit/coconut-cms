/** The three regions panels open in: beside the canvas on each side, and under it. */
export const CONTAINERS = ['left', 'right', 'bottom'] as const;

/** One of CONTAINERS. */
export type Container = typeof CONTAINERS[number];
