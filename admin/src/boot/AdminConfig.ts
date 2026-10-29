/**
 * What the admin page hands the app, as JSON in its config script: where the
 * API is, and the session's CSRF token. Checked on read, so a broken page
 * fails with a clear message, not a blank screen.
 */
export class AdminConfig {
    /** The id of the script element the admin page writes it in. */
    public static readonly ELEMENT_ID = 'coconut-admin-config';

    /**
     * @param apiUrl Where operations are mounted: "/api".
     * @param csrfToken The session's token, sent with every call.
     */
    private constructor(
        public readonly apiUrl: string,
        public readonly csrfToken: string,
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

        const {apiUrl, csrfToken} = data as Record<string, unknown>;
        if (typeof apiUrl !== 'string' || apiUrl === '') {
            throw new Error('The admin config has no apiUrl.');
        }

        if (typeof csrfToken !== 'string' || csrfToken === '') {
            throw new Error('The admin config has no csrfToken.');
        }

        return new AdminConfig(apiUrl, csrfToken);
    }
}
