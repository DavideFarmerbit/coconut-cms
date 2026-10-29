/**
 * The admin's entry: reads the page's config, starts the shared runtime the
 * public module exports, registers the admin's own tools, and runs the app.
 * The runtime comes from "@coconut-cms/admin"'s own chunk, so plugins
 * importing that name reach this very instance.
 */
import {AdminApp} from './app/AdminApp.ts';
import {AdminConfig} from './boot/AdminConfig.ts';
import {Tooltips} from './dom/Tooltips.ts';
import {admin, ApiClient} from './public/index.ts';
import {TypesTool} from './tools/types/TypesTool.ts';

const config = AdminConfig.fromDocument(document);
admin.start(new ApiClient(config.apiUrl, config.csrfToken));
Tooltips.install(document);
TypesTool.register(admin);
void new AdminApp(admin, document.body).start();
