import type {CollapseMemory} from '../public/properties/CollapseMemory.ts';

/** A CollapseMemory kept only while its view lives: the default. */
export class MemoryCollapse implements CollapseMemory {
    private readonly collapsed = new Set<string>();

    public isCollapsed(key: string): boolean {
        return this.collapsed.has(key);
    }

    public setCollapsed(key: string, collapsed: boolean): void {
        if (collapsed) {
            this.collapsed.add(key);
        } else {
            this.collapsed.delete(key);
        }
    }
}
