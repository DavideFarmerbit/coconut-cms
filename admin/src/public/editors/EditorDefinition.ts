import type {TabTypeId} from '../workspace/Ids.ts';

/**
 * Which tab type edits a kind of thing: the entries of `type` and of every
 * type extending it, or the types extending `type`. Registered through
 * admin.editors.
 */
export interface EditorDefinition {
    /** The entity type it's for, its subtypes included. */
    readonly type: string;
    /** The tab type opened to edit one. */
    readonly tab: TabTypeId;
}
