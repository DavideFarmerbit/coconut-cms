import type {AdminPluginClass} from '../public/AdminPlugin.ts';
import type {AdminRuntime} from '../public/AdminRuntime.ts';
import type {PluginEntry} from './PluginEntry.ts';

/** A plugin that couldn't be loaded, and why. */
export interface PluginFailure {
    readonly module: string;
    readonly message: string;
}

/**
 * Loads the modules' admin code, one at a time in boot order: imports each
 * entry, builds its default export, and lets it register. A plugin that
 * fails to load or to register is skipped and reported; the admin starts
 * without it.
 */
export class PluginLoader {
    /** Loads every one of `plugins` into `runtime`. @return The plugins skipped. */
    public static async loadAll(plugins: readonly PluginEntry[], runtime: AdminRuntime): Promise<readonly PluginFailure[]> {
        const failures: PluginFailure[] = [];
        for (const plugin of plugins) {
            try {
                const module: unknown = await import(/* @vite-ignore */ plugin.script);
                const Plugin = (module as {default?: unknown}).default;
                if (typeof Plugin !== 'function') {
                    throw new Error('its entry has no default export: a class implementing AdminPlugin.');
                }

                new (Plugin as AdminPluginClass)().register(runtime);
            } catch (failure) {
                const message = failure instanceof Error ? failure.message : String(failure);
                failures.push({module: plugin.module, message});
                console.error(`The admin skipped ${plugin.module}'s admin code: ${message}`, failure);
            }
        }

        return failures;
    }
}
