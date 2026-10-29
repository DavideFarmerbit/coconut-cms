import {resolve} from 'node:path';

/** The admin's sources' folder: the build's root. */
export const ADMIN_ROOT = import.meta.dirname;

/** The admin's own entries, by chunk name. */
export const ADMIN_INPUT = {
    'main': 'src/main.ts',
    'admin-module': 'src/public/index.ts',
    'admin': 'src/styles/admin.scss',
    'theme-light': 'src/styles/themes/light.scss',
};

/** The chunk name a module's source entry is built under. */
export function pluginChunk(module) {
    return `plugin-${module}`;
}

/**
 * The Vite configuration building the admin into `outDir`, with `plugins`,
 * each module's source entry by its chunk name, built in the same build.
 * URLs are relative, so the output can be served from any folder.
 * "@coconut-cms/admin" resolves to the admin's own public module, so a
 * module's admin code shares the admin's code, never bundling a copy.
 */
export function adminConfig({outDir, plugins = {}}) {
    return {
        configFile: false,
        root: ADMIN_ROOT,
        base: './',
        publicDir: false,
        resolve: {
            alias: {'@coconut-cms/admin': resolve(ADMIN_ROOT, 'src/public/index.ts')},
        },
        build: {
            outDir,
            emptyOutDir: true,
            manifest: true,
            rolldownOptions: {
                input: {...ADMIN_INPUT, ...plugins},
                // Every entry keeps its exports: the public module's for plugins
                // loaded through the import map, a plugin's default export for the admin.
                preserveEntrySignatures: 'strict',
            },
        },
    };
}
