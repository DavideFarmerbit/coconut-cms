import type {FakeBackend} from './FakeBackend.ts';
import type {ApiClient} from '../src/public/api/ApiClient.ts';
import {Auth} from '../src/public/auth/Auth.ts';
import {Editors} from '../src/public/editors/Editors.ts';
import {PropertyEditors} from '../src/public/properties/PropertyEditors.ts';
import {Services} from '../src/public/services/Services.ts';
import type {ServiceType} from '../src/public/services/ServiceType.ts';
import type {JsonValue} from '../src/public/workspace/Json.ts';
import type {TabTypeId} from '../src/public/workspace/Ids.ts';
import type {TabOpening} from '../src/public/workspace/TabOpening.ts';
import type {ViewContext} from '../src/public/workspace/ViewContext.ts';
import type {ViewState} from '../src/public/workspace/ViewState.ts';
import type {Workspace} from '../src/public/workspace/Workspace.ts';

/** A tab a view opened. */
export interface OpenedTab {
    readonly type: TabTypeId;
    readonly title: string;
    readonly params: JsonValue;
    readonly key: string;
    readonly opening: TabOpening;
}

/** A view's state kept in memory. */
class MemoryState implements ViewState {
    private readonly values = new Map<string, JsonValue>();

    public get(name: string): JsonValue | undefined {
        return this.values.get(name);
    }

    public set(name: string, value: JsonValue): void {
        this.values.set(name, value);
    }
}

/** A workspace remembering the tabs opened. */
class RecordingWorkspace implements Workspace {
    public readonly opened: OpenedTab[] = [];

    public openTab(type: TabTypeId, title: string, params: JsonValue, key: string, opening: TabOpening): void {
        this.opened.push({type, title, params, key, opening});
    }
}

/**
 * A ViewContext over a fake backend, as the shell gives one: the services
 * of ada, signed in, state kept in memory, and a workspace remembering the
 * tabs a view opens.
 */
export class FakeContext implements ViewContext {
    public readonly api: ApiClient;
    public readonly state: ViewState = new MemoryState();
    public readonly workspace = new RecordingWorkspace();
    public readonly properties = new PropertyEditors();
    public readonly editors = new Editors();
    /** Whether the view marked itself dirty last. */
    public dirty = false;
    /** The title the view gave its tab last; null when it gave none. */
    public title: string | null = null;
    private readonly services: Services;

    public constructor(backend: FakeBackend, public readonly params: JsonValue = null) {
        this.api = backend.client();
        this.services = new Services(this.api, new Auth(this.api), {id: 'u1', name: 'ada', roles: ['admin']});
    }

    public service<T>(type: ServiceType<T>): T {
        return this.services.get(type);
    }

    public markDirty(dirty: boolean): void {
        this.dirty = dirty;
    }

    public setTitle(title: string): void {
        this.title = title;
    }
}
