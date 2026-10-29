import type {TabId, TabTypeId} from '../../public/workspace/Ids.ts';
import type {JsonValue} from '../../public/workspace/Json.ts';

/** One open tab, as the layout keeps it. */
export interface TabData {
    readonly id: TabId;
    readonly type: TabTypeId;
    readonly title: string;
    /** What its view shows: a type's identifier, an entity's id. */
    readonly params: JsonValue;
    /** Opening a tab of the same type with the same key focuses this one instead. */
    readonly key: string;
}
