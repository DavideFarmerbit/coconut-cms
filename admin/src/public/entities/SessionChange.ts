/**
 * What an EntitySession tells its views. Each names the view that made the
 * change, when one did, so that view keeps its widget.
 * - `values`: the values at `paths` changed, their shape kept;
 * - `shape`: what's at `path` changed shape: items added, removed or moved,
 *   an owned object's class picked, a reset; the rows under it are built again;
 * - `state`: dirty, saving, errors or loading changed;
 * - `reloaded`: everything may differ: loaded, saved, reverted.
 */
export type SessionChange =
    | {readonly kind: 'values'; readonly paths: readonly string[]; readonly source: object | null}
    | {readonly kind: 'shape'; readonly path: string; readonly source: object | null}
    | {readonly kind: 'state'}
    | {readonly kind: 'reloaded'};

/** Told of each SessionChange. */
export type SessionListener = (change: SessionChange) => void;
