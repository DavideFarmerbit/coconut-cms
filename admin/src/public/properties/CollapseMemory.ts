/**
 * Where a view remembers what's collapsed: properties by path, groups by
 * type. A view gives one to its grid or rows; the default forgets on close.
 */
export interface CollapseMemory {
    /** Whether `key` is collapsed. */
    isCollapsed(key: string): boolean;

    /** Remembers that `key` is collapsed, or not. */
    setCollapsed(key: string, collapsed: boolean): void;
}
