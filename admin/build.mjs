/**
 * Builds the admin with every module's admin code, what a project runs as
 * `npm run build:admin`:
 *
 *     npm --prefix vendor/xylo-is-coding/coconut-cms run build:admin -- --project "$PWD"
 *
 * 1. Asks the project which modules have admin code, through
 *    `vendor/bin/coconut coconut-cms:admin.code`.
 * 2. Builds the admin, each module's source entry with it, into
 *    `<project>/public/dist/admin`, or `--out`.
 * 3. Copies each module's built folder into `plugins/<module>/` there.
 * 4. Writes `plugins.json` there: each module's entry script and
 *    stylesheets, what the admin page loads.
 *
 * Without --project it builds the admin alone, into the package's dist/admin.
 */
import {execFileSync} from 'node:child_process';
import {cpSync, existsSync, readFileSync, writeFileSync} from 'node:fs';
import {join, resolve} from 'node:path';
import {parseArgs} from 'node:util';
import {build} from 'vite';
import {ADMIN_ROOT, adminConfig, pluginChunk} from './config.mjs';

const {values: options} = parseArgs({options: {project: {type: 'string'}, out: {type: 'string'}}});
const project = options.project === undefined ? undefined : resolve(options.project);
const outDir = resolve(options.out ?? (project === undefined ? join(ADMIN_ROOT, '../dist/admin') : join(project, 'public/dist/admin')));

/** Every module's admin code, as the project lists it; none without a project. */
function adminCode() {
    if (project === undefined) {
        return [];
    }

    const output = execFileSync(join(project, 'vendor/bin/coconut'), ['coconut-cms:admin.code', '--output=json'], {cwd: project, encoding: 'utf8'});

    return JSON.parse(output).entries;
}

const code = adminCode();
const sources = code.filter((entry) => entry.kind === 'source');
const builts = code.filter((entry) => entry.kind === 'built');

await build(adminConfig({
    outDir,
    plugins: Object.fromEntries(sources.map((entry) => [pluginChunk(entry.module), entry.path])),
}));

const manifest = JSON.parse(readFileSync(join(outDir, '.vite/manifest.json'), 'utf8'));
const plugins = sources.map((entry) => {
    const chunk = Object.values(manifest).find((each) => each.isEntry && each.name === pluginChunk(entry.module));
    if (chunk === undefined) {
        throw new Error(`The build has no entry for ${entry.module}'s admin code, ${entry.path}.`);
    }

    return {module: entry.module, script: chunk.file, css: chunk.css ?? []};
});

for (const entry of builts) {
    const described = join(entry.path, 'admin-plugin.json');
    if (!existsSync(described)) {
        throw new Error(`${entry.module}'s built admin code has no admin-plugin.json: ${described}.`);
    }

    const {entry: script, css = []} = JSON.parse(readFileSync(described, 'utf8'));
    const folder = `plugins/${entry.module}`;
    cpSync(entry.path, join(outDir, folder), {recursive: true});
    plugins.push({module: entry.module, script: `${folder}/${script}`, css: css.map((file) => `${folder}/${file}`)});
}

writeFileSync(join(outDir, 'plugins.json'), JSON.stringify({plugins}, null, 2));
console.log(`Admin built in ${outDir}, with ${plugins.length} plugin${plugins.length === 1 ? '' : 's'}${plugins.length > 0 ? ': ' + plugins.map((plugin) => plugin.module).join(', ') : ''}.`);
