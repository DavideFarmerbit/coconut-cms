import type {TabTypeId} from './Ids.ts';
import type {JsonValue} from './Json.ts';

/** What a view asks of the workspace around it. */
export interface Workspace {
    /**
     * Opens a tab of `type` and shows it; one of `type` open with `key`
     * already is shown instead.
     * @param params What the tab's view shows: a type's identifier, an entity's id.
     * @param key What makes two tabs of one type the same tab.
     */
    openTab(type: TabTypeId, title: string, params: JsonValue, key: string): void;
}
