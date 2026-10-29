import type {TabTypeId} from './Ids.ts';
import type {JsonValue} from './Json.ts';
import type {TabOpening} from './TabOpening.ts';

/** What a view asks of the workspace around it. */
export interface Workspace {
    /**
     * Shows a tab of `type`: the one open with `key` already, or one opened
     * as `opening` says.
     * @param params What the tab's view shows: a type's identifier, an entity's id.
     * @param key What makes two tabs of one type the same tab.
     * @param opening "reuse" to replace the tab of `type` shown most
     *        recently, "new" for another tab.
     */
    openTab(type: TabTypeId, title: string, params: JsonValue, key: string, opening: TabOpening): void;
}
