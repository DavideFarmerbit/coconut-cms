import type {WrittenSave} from '../draft/WrittenSave.ts';
import type {SchemaReport} from '../SchemaReport.ts';

/**
 * A save written from the draft and previewed: what the admin confirms.
 * Confirming sends the same operations, at the same revision.
 */
export class SchemaPreview {
    public constructor(
        public readonly save: WrittenSave,
        public readonly report: SchemaReport,
    ) {
    }

    /** Whether the save would be refused: a break the policy blocks, an entry that can't move, values held twice. Fix the draft first. */
    public get refused(): boolean {
        return this.report.blocked.length > 0 || Object.keys(this.report.failures).length > 0 || this.report.duplicates.length > 0;
    }

    /** Whether it breaks other types: confirming accepts the breaks. */
    public get breaks(): boolean {
        return this.report.breaks.length > 0;
    }
}
