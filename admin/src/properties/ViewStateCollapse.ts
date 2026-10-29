import type {CollapseMemory} from '../public/properties/CollapseMemory.ts';
import type {ViewState} from '../public/workspace/ViewState.ts';

/**
 * A CollapseMemory kept in a view's saved state, under `name`, one list per
 * entity type: a tab remembers what's collapsed for every car alike.
 */
export class ViewStateCollapse implements CollapseMemory {
    /**
     * @param name The state's name: "collapsedProperties", "collapsedGroups".
     * @param type The entity type the keys are remembered for.
     */
    public constructor(
        private readonly state: ViewState,
        private readonly name: string,
        private readonly type: string,
    ) {
    }

    public isCollapsed(key: string): boolean {
        return this.keys().includes(key);
    }

    public setCollapsed(key: string, collapsed: boolean): void {
        const others = this.keys().filter((each) => each !== key);
        this.state.set(this.name, {...this.byType(), [this.type]: collapsed ? [...others, key] : others});
    }

    /** The keys collapsed for the type. */
    private keys(): readonly string[] {
        const keys = this.byType()[this.type];

        return Array.isArray(keys) ? keys.filter((each): each is string => typeof each === 'string') : [];
    }

    /** The state, by type. */
    private byType(): Readonly<Record<string, readonly string[]>> {
        const state = this.state.get(this.name);

        return typeof state === 'object' && state !== null && !Array.isArray(state) ? state as Record<string, readonly string[]> : {};
    }
}
