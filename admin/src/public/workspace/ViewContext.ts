import type {ApiClient} from '../api/ApiClient.ts';
import type {EntityStore} from '../entities/EntityStore.ts';
import type {PropertyEditors} from '../properties/PropertyEditors.ts';
import type {TypeStore} from '../types/TypeStore.ts';
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
    /** The entities, and the sessions editing them. */
    readonly entities: EntityStore;
    /** The entity types the user may read: read its catalog when showing something, never keep one. */
    readonly types: TypeStore;

    /**
     * Tells whether the view has unsaved changes: its tab shows a mark, a
     * reusing open never replaces it, and leaving the page asks first.
     */
    markDirty(dirty: boolean): void;

    /** Renames its tab: after a save changed what it shows. Nothing for a tool's panel. */
    setTitle(title: string): void;
}
