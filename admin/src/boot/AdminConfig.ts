import type {PluginEntry} from './PluginEntry.ts';

/**
 * What the admin page hands the app, as JSON in its config script: where the
 * API is, the session's CSRF token, and the modules' admin code. Checked on
 * read, so a broken page fails with a clear message, not a blank screen.
 */
export class AdminConfig {
    /** The id of the script element the admin page writes it in. */
    public static readonly ELEMENT_ID = 'coconut-admin-config';

    /**
     * @param apiUrl Where operations are mounted: "/api".
     * @param csrfToken The session's token, sent with every call.
     * @param plugins Every module's admin code, in boot order.
     */
    private constructor(
        public readonly apiUrl: string,
        public readonly csrfToken: string,
        public readonly plugins: readonly PluginEntry[],
    ) {
    }

    /** The config the page's script element holds. Throws when there's none, or it's broken. */
    public static fromDocument(document: Document): AdminConfig {
        const element = document.getElementById(AdminConfig.ELEMENT_ID);
        if (element === null) {
            throw new Error(`The admin page has no #${AdminConfig.ELEMENT_ID} script: it wasn't served by Admin::routes().`);
        }

        return AdminConfig.parse(element.textContent ?? '');
    }

    /** The config `json` holds. Throws naming what's missing or wrong. */
    public static parse(json: string): AdminConfig {
        let data: unknown;
        try {
            data = JSON.parse(json);
        } catch {
            throw new Error('The admin config isn\'t JSON.');
        }

        if (typeof data !== 'object' || data === null) {
            throw new Error('The admin config isn\'t an object.');
        }

        const {apiUrl, csrfToken, plugins = []} = data as Record<string, unknown>;
        if (typeof apiUrl !== 'string' || apiUrl === '') {
            throw new Error('The admin config has no apiUrl.');
        }

        if (typeof csrfToken !== 'string' || csrfToken === '') {
            throw new Error('The admin config has no csrfToken.');
        }

        if (!Array.isArray(plugins) || !plugins.every(AdminConfig.isPluginEntry)) {
            throw new Error('The admin config\'s plugins aren\'t a list of {module, script}.');
        }

        return new AdminConfig(apiUrl, csrfToken, plugins);
    }

    /** Whether `value` is a plugin entry. */
    private static isPluginEntry(value: unknown): value is PluginEntry {
        if (typeof value !== 'object' || value === null) {
            return false;
        }

        const {module, script} = value as Record<string, unknown>;

        return typeof module === 'string' && typeof script === 'string';
    }
}
