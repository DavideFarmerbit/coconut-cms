/**
 * The admin's entry: reads the page's config, starts the shared runtime the
 * public module exports, registers the admin's own tools, then the modules'
 * admin code, and runs the app. The runtime comes from "@coconut-cms/admin"'s
 * own chunk, so every plugin reaches this very instance.
 */
import {AdminApp} from './app/AdminApp.ts';
import {AdminConfig} from './boot/AdminConfig.ts';
import {PluginLoader} from './boot/PluginLoader.ts';
import {Tooltips} from './dom/Tooltips.ts';
import {DefaultEditors} from './properties/DefaultEditors.ts';
import {admin, ApiClient} from './public/index.ts';
import {TypesTool} from './tools/types/TypesTool.ts';

/** Starts the admin. */
async function start(): Promise<void> {
    const config = AdminConfig.fromDocument(document);
    admin.start(new ApiClient(config.apiUrl, config.csrfToken));
    Tooltips.install(document);
    // The admin's own first: a plugin's customizations replace its default editors.
    DefaultEditors.register(admin.properties);
    TypesTool.register(admin);
    // Before the workspace: every tool must be there when the saved layout is reconciled.
    await PluginLoader.loadAll(config.plugins, admin);
    await new AdminApp(admin, document.body).start();
}

void start();
