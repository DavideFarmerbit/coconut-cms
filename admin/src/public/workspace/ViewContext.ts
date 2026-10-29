import type {ApiClient} from '../api/ApiClient.ts';
import type {TypeCatalog} from '../types/TypeCatalog.ts';
import type {JsonValue} from './Json.ts';
import type {ViewState} from './ViewState.ts';
import type {Workspace} from './Workspace.ts';

/** What a PanelView is built with. */
export interface ViewContext {
    /** The client every call to the backend goes through. */
    readonly api: ApiClient;
    /** Its own saved state. */
    readonly state: ViewState;
    /** The workspace around it: to open tabs. */
    readonly workspace: Workspace;
    /** A tab's parameters, as it was opened with; null for a tool's panel. */
    readonly params: JsonValue;

    /** Every entity type the user may read, loaded once per page. */
    typeCatalog(): Promise<TypeCatalog>;
}
