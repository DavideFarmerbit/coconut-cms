import type {SchemaReport} from '../SchemaReport.ts';
import type {PreviewOutcome} from './PreviewOutcome.ts';

/**
 * What confirming a preview gives:
 * - `saved`: the save ran; the types and the draft are loaded again;
 * - `cancelled`: an entity editor's unsaved changes weren't settled, nothing was sent;
 * - `stale`: the schema was saved by someone else since the draft loaded;
 * - `refused`: the server refused it, content having changed since the
 *   preview; `outcome` is the preview asked again, showing why.
 */
export type SaveOutcome =
    | {readonly kind: 'saved'; readonly report: SchemaReport}
    | {readonly kind: 'cancelled'}
    | {readonly kind: 'stale'}
    | {readonly kind: 'refused'; readonly outcome: PreviewOutcome};
