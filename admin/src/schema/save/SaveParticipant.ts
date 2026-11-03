import type {SchemaDraft} from '../draft/SchemaDraft.ts';
import type {WrittenSave} from '../draft/WrittenSave.ts';
import type {SchemaReport} from '../SchemaReport.ts';

/**
 * A plugin taking part in every schema save: what keeps data about types
 * in step with them. Registered with SchemaSession.participate().
 *
 * beforeSave() runs once the save is confirmed, before it's sent: it may
 * write what the save needs, such as a record of what it's about to make.
 * Throwing cancels the save, nothing sent. afterSave() runs once it saved
 * and the draft loaded again: a failure there is the participant's to
 * recover from, so the save it follows still counts.
 */
export interface SaveParticipant {
    /** Runs before `save`, written from `draft`, is sent. Throwing cancels it. */
    beforeSave(save: WrittenSave, draft: SchemaDraft): Promise<void>;

    /** Runs once `report`'s save ran and the draft loaded again. */
    afterSave(report: SchemaReport): Promise<void>;
}
