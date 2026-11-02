import type {SaveProblem} from '../draft/SaveProblem.ts';
import type {SchemaPreview} from './SchemaPreview.ts';

/**
 * What asking for a preview gives:
 * - `unchanged`: the draft is as loaded, there's nothing to save;
 * - `problems`: the draft can't be written yet, nothing was sent;
 * - `stale`: the schema was saved by someone else since the draft loaded;
 * - `previewed`: the preview to confirm.
 */
export type PreviewOutcome =
    | {readonly kind: 'unchanged'}
    | {readonly kind: 'problems'; readonly problems: readonly SaveProblem[]}
    | {readonly kind: 'stale'}
    | {readonly kind: 'previewed'; readonly preview: SchemaPreview};
