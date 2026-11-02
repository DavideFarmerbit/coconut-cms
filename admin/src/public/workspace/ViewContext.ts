import type {ApiClient} from '../api/ApiClient.ts';
import type {PropertyEditors} from '../properties/PropertyEditors.ts';
import type {ServiceType} from '../services/ServiceType.ts';
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
    /** Every property editor: what shows an entity's values. */
    readonly properties: PropertyEditors;

    /**
     * The one `type` service of the user signed in, shared by every view:
     * `context.service(EntityStore)`, `context.service(TypeStore)`, or a
     * tool's own. What several views keep in sync lives in one.
     */
    service<T>(type: ServiceType<T>): T;

    /**
     * Tells whether the view has unsaved changes: its tab shows a mark, a
     * reusing open never replaces it, and leaving the page asks first.
     */
    markDirty(dirty: boolean): void;

    /** Renames its tab: after a save changed what it shows. Nothing for a tool's panel. */
    setTitle(title: string): void;
}
