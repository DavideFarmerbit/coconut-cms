import type {JsonValue} from '../../public/workspace/Json.ts';
import type {ViewState} from '../../public/workspace/ViewState.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';

/** A view's saved state, kept in the layout under the view's key, so it's saved with it. */
export class LayoutViewState implements ViewState {
    public constructor(
        private readonly store: LayoutStore,
        private readonly key: string,
    ) {
    }

    public get(name: string): JsonValue | undefined {
        return this.store.current.stateOf(this.key)[name];
    }

    public set(name: string, value: JsonValue): void {
        this.store.update(this.store.current.withViewState(this.key, name, value));
    }
}
