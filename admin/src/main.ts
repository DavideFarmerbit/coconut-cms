/**
 * The admin's entry: reads the page's config, starts the shared runtime the
 * public module exports, and runs the app. The runtime comes from
 * "@coconut-cms/admin"'s own chunk, so plugins importing that name reach
 * this very instance.
 */
import {AdminApp} from './app/AdminApp.ts';
import {AdminConfig} from './boot/AdminConfig.ts';
import {admin, ApiClient} from './public/index.ts';

const config = AdminConfig.fromDocument(document);
admin.start(new ApiClient(config.apiUrl, config.csrfToken));
void new AdminApp(admin, document.body).start();
