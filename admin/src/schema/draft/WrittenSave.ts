import type {SaveProblem} from './SaveProblem.ts';
import type {SchemaOperationData} from './SchemaOperationData.ts';

/**
 * A schema draft written as the operations of one editor save. Sent only
 * when there's no problem: each one is something the admin chooses or
 * fixes first.
 */
export interface WrittenSave {
    /** The schema revision the draft loaded at: what the save is built on. */
    readonly revision: number;
    /** In the order they run. */
    readonly operations: readonly SchemaOperationData[];
    readonly problems: readonly SaveProblem[];
}
