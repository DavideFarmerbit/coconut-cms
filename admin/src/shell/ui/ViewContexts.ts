import type {AdminRuntime} from '../../public/AdminRuntime.ts';
import {tabId} from '../../public/workspace/Ids.ts';
import type {JsonValue} from '../../public/workspace/Json.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import type {Workspace} from '../../public/workspace/Workspace.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';
import type {DirtyViews} from './DirtyViews.ts';
import {LayoutViewState} from './LayoutViewState.ts';

/** Makes the context each view is built with. */
export class ViewContexts {
    /** What a tab's view key starts with, before its tab's id: Layout.tabKey(). */
    private static readonly TAB_PREFIX = 'tab:';

    public constructor(
        private readonly runtime: AdminRuntime,
        private readonly store: LayoutStore,
        private readonly workspace: Workspace,
        private readonly dirty: DirtyViews,
    ) {
    }

    /** The context of the view under `key`, given `params`: a tab's parameters, null for a tool. */
    public create(key: string, params: JsonValue): ViewContext {
        const runtime = this.runtime;
        const dirty = this.dirty;
        const store = this.store;
        const tab = key.startsWith(ViewContexts.TAB_PREFIX) ? tabId(key.slice(ViewContexts.TAB_PREFIX.length)) : null;

        return {
            api: runtime.api,
            state: new LayoutViewState(this.store, key),
            workspace: this.workspace,
            params,
            properties: runtime.properties,
            entities: runtime.entities,
            types: runtime.types,
            markDirty: (isDirty: boolean) => dirty.set(key, isDirty),
            setTitle: (title: string) => {
                if (tab !== null) {
                    store.update(store.current.retitleTab(tab, title));
                }
            },
        };
    }
}
