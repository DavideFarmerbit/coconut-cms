import type {AdminRuntime} from '../../public/AdminRuntime.ts';
import type {JsonValue} from '../../public/workspace/Json.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import type {Workspace} from '../../public/workspace/Workspace.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';
import {LayoutViewState} from './LayoutViewState.ts';

/** Makes the context each view is built with. */
export class ViewContexts {
    public constructor(
        private readonly runtime: AdminRuntime,
        private readonly store: LayoutStore,
        private readonly workspace: Workspace,
    ) {
    }

    /** The context of the view under `key`, given `params`: a tab's parameters, null for a tool. */
    public create(key: string, params: JsonValue): ViewContext {
        const runtime = this.runtime;

        return {
            api: runtime.api,
            state: new LayoutViewState(this.store, key),
            workspace: this.workspace,
            params,
            typeCatalog: () => runtime.typeCatalog(),
        };
    }
}
