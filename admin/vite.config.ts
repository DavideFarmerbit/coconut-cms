import {defineConfig} from 'vite';
import {resolve} from 'node:path';

/**
 * Builds the admin: its app, its public module (what plugins import as
 * "@coconut-cms/admin"), its base stylesheet and its bundled themes, with a
 * manifest the PHP side reads URLs from. Relative URLs, so the output can be
 * served from any folder. A project builds it into its own public folder:
 *
 *     npm run build:admin -- --outDir /path/to/project/public/dist/admin
 */
export default defineConfig({
    root: import.meta.dirname,
    base: './',
    publicDir: false,
    build: {
        outDir: resolve(import.meta.dirname, '../dist/admin'),
        emptyOutDir: true,
        manifest: true,
        rolldownOptions: {
            input: [
                'src/main.ts',
                'src/public/index.ts',
                'src/styles/admin.scss',
                'src/styles/themes/light.scss',
            ],
            // The public module keeps every export, so plugins importing it
            // through the import map reach the running app's own instances.
            preserveEntrySignatures: 'strict',
        },
    },
});
