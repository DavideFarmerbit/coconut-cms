import type {ApiClient} from './api/ApiClient.ts';
import {Auth} from './auth/Auth.ts';
import type {User} from './auth/User.ts';
import {Editors} from './editors/Editors.ts';
import {PropertyEditors} from './properties/PropertyEditors.ts';
import {Services} from './services/Services.ts';
import type {ServiceType} from './services/ServiceType.ts';
import type {TabTypeId, ToolId} from './workspace/Ids.ts';
import {Registry} from './workspace/Registry.ts';
import type {TabTypeDefinition} from './workspace/TabTypeDefinition.ts';
import type {ToolDefinition} from './workspace/ToolDefinition.ts';

/**
 * The running admin, as plugins reach it: its tools and tab types, the API
 * client it calls the backend with, who's signed in, and their services:
 * the types, the entities, and what any tool shares between its views.
 * One instance, `admin`, exported by the
 * public module: a plugin importing "@coconut-cms/admin" gets the app's own,
 * through the import map.
 */
export class AdminRuntime {
    /** Every tool the shell offers: register one before the shell starts. */
    public readonly tools = new Registry<ToolId, ToolDefinition>('tool');

    /** Every kind of tab the canvas opens. */
    public readonly tabTypes = new Registry<TabTypeId, TabTypeDefinition>('tab type');

    /** Every property editor: the admin's defaults, and customizations replacing them. */
    public readonly properties = new PropertyEditors();

    /** Which editor opens an entry, and which a type: register one before the shell starts. */
    public readonly editors = new Editors();

    /** Set once the app has started; undefined before. */
    private client: ApiClient | undefined;

    /** Who's signed in; set once the app has started. */
    private authentication: Auth | undefined;

    /** The services of whoever is signed in; undefined while nobody is. */
    private services: Services | undefined;

    /** The client every call to the backend goes through. Throws before the app started. */
    public get api(): ApiClient {
        if (this.client === undefined) {
            throw new Error('The admin hasn\'t started: there is no API client yet.');
        }

        return this.client;
    }

    /**
     * The one `type` service of the user signed in, created the first time
     * it's asked for: `admin.service(EntityStore)`. Throws while nobody is
     * signed in.
     */
    public service<T>(type: ServiceType<T>): T {
        if (this.services === undefined) {
            throw new Error(`Nobody is signed in: there is no ${type.name}.`);
        }

        return this.services.get(type);
    }

    /** Whether a service holds work leaving the page would lose. */
    public get hasUnsaved(): boolean {
        return this.services?.hasUnsaved ?? false;
    }

    /** Who's signed in, signing in and out. Throws before the app started. */
    public get auth(): Auth {
        if (this.authentication === undefined) {
            throw new Error('The admin hasn\'t started: there is no auth yet.');
        }

        return this.authentication;
    }

    /** Who's signed in; null for nobody. */
    public get user(): User | null {
        return this.authentication?.user ?? null;
    }

    /** Called once by the app at start, with its client. */
    public start(client: ApiClient): void {
        if (this.client !== undefined) {
            throw new Error('The admin has started already.');
        }

        this.client = client;
        this.authentication = new Auth(client);
        // Followed first: the services start over before any view hears of the user.
        this.authentication.onChange((user) => this.userChanged(user));
    }

    /**
     * Starts the services over for `user`: what they may read differs. The
     * last user's are disposed of, so the views going let their work go
     * without asking; nobody has no services.
     */
    private userChanged(user: User | null): void {
        this.services?.dispose();
        this.services = user === null ? undefined : new Services(this.api, this.auth, user);
    }
}
