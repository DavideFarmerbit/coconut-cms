/** One entry of a ContextMenu. */
export interface MenuItem {
    readonly label: string;

    /** Called when it's chosen; the menu is closed by then. */
    run(): void;
}
