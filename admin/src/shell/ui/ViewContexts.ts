import type {ApiClient} from '../../public/api/ApiClient.ts';
import type {JsonValue} from '../../public/workspace/Json.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import type {Workspace} from '../../public/workspace/Workspace.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';
import {LayoutViewState} from './LayoutViewState.ts';

/** Makes the context each view is built with. */
export class ViewContexts {
    public constructor(
        private readonly api: ApiClient,
        private readonly store: LayoutStore,
        private readonly workspace: Workspace,
    ) {
    }

    /** The context of the view under `key`, given `params`: a tab's parameters, null for a tool. */
    public create(key: string, params: JsonValue): ViewContext {
        return {api: this.api, state: new LayoutViewState(this.store, key), workspace: this.workspace, params};
    }
}
