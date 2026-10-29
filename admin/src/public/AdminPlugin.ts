import type {AdminRuntime} from './AdminRuntime.ts';

/**
 * A module's admin code: the default export of its entry is a class
 * implementing this. The admin builds it with no arguments, after its own
 * tools and before the workspace, and calls register() once.
 *
 *     export default class CarsPlugin implements AdminPlugin {
 *         public register(admin: AdminRuntime): void {
 *             admin.tools.register({id: toolId('demo:cars'), ...});
 *         }
 *     }
 */
export interface AdminPlugin {
    /** Registers what it adds: tools, tab types. */
    register(admin: AdminRuntime): void;
}

/** An AdminPlugin class: what a module's entry exports by default. */
export type AdminPluginClass = new () => AdminPlugin;
