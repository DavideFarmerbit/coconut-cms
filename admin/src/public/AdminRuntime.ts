import type {ApiClient} from './api/ApiClient.ts';
import type {User} from './auth/User.ts';
import {PropertyEditors} from './properties/PropertyEditors.ts';
import {TypeCatalog} from './types/TypeCatalog.ts';
import type {TypeList} from './types/TypeDescription.ts';
import type {TabTypeId, ToolId} from './workspace/Ids.ts';
import {Registry} from './workspace/Registry.ts';
import type {TabTypeDefinition} from './workspace/TabTypeDefinition.ts';
import type {ToolDefinition} from './workspace/ToolDefinition.ts';

/**
 * The running admin, as plugins reach it: its tools and tab types, the API
 * client it calls the backend with, and who's signed in. One instance, `admin`, exported by the
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

    /** Set once the app has started; undefined before. */
    private client: ApiClient | undefined;

    /** Who's signed in; null before signing in. */
    private signedIn: User | null = null;

    /** The type catalog, once asked for. */
    private catalog: Promise<TypeCatalog> | undefined;

    /** The client every call to the backend goes through. Throws before the app started. */
    public get api(): ApiClient {
        if (this.client === undefined) {
            throw new Error('The admin hasn\'t started: there is no API client yet.');
        }

        return this.client;
    }

    /** Who's signed in; null for nobody. */
    public get user(): User | null {
        return this.signedIn;
    }

    /**
     * Every entity type the user may read, loaded once per page: types
     * change only with a migration, and a migration means a reload.
     */
    public typeCatalog(): Promise<TypeCatalog> {
        this.catalog ??= this.api.call<TypeList>('coconut-cms:types.list').then((list) => TypeCatalog.of(list.types));

        return this.catalog;
    }

    /** Called once by the app at start, with its client. */
    public start(client: ApiClient): void {
        if (this.client !== undefined) {
            throw new Error('The admin has started already.');
        }

        this.client = client;
    }

    /** Called by the app when someone signs in or out: what they may read differs, so the catalog is forgotten. */
    public setUser(user: User | null): void {
        this.signedIn = user;
        this.catalog = undefined;
    }
}
