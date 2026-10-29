import {DialogUnsavedQuestion} from '../entities/DialogUnsavedQuestion.ts';
import type {ApiClient} from './api/ApiClient.ts';
import type {User} from './auth/User.ts';
import {EntityStore} from './entities/EntityStore.ts';
import {PropertyEditors} from './properties/PropertyEditors.ts';
import {TypeCatalog} from './types/TypeCatalog.ts';
import type {TypeList} from './types/TypeDescription.ts';
import type {TabTypeId, ToolId} from './workspace/Ids.ts';
import {Registry} from './workspace/Registry.ts';
import type {TabTypeDefinition} from './workspace/TabTypeDefinition.ts';
import type {ToolDefinition} from './workspace/ToolDefinition.ts';

/**
 * The running admin, as plugins reach it: its tools and tab types, the API
 * client it calls the backend with, the entities, and who's signed in. One instance, `admin`, exported by the
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

    /** The entities, for whoever is signed in; set when the app starts. */
    private store: EntityStore | undefined;

    /** The client every call to the backend goes through. Throws before the app started. */
    public get api(): ApiClient {
        if (this.client === undefined) {
            throw new Error('The admin hasn\'t started: there is no API client yet.');
        }

        return this.client;
    }

    /** The entities the admin works with, and their sessions. Throws before the app started. */
    public get entities(): EntityStore {
        if (this.store === undefined) {
            throw new Error('The admin hasn\'t started: there is no entity store yet.');
        }

        return this.store;
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
        this.store = this.newStore(client);
    }

    /** Called by the app when someone signs in or out: what they may read differs, so the catalog and the entities are forgotten. */
    public setUser(user: User | null): void {
        this.signedIn = user;
        this.catalog = undefined;
        if (this.client !== undefined) {
            this.store = this.newStore(this.client);
        }
    }

    /** An empty store calling the backend through `client`. */
    private newStore(client: ApiClient): EntityStore {
        return new EntityStore(client, () => this.typeCatalog(), new DialogUnsavedQuestion());
    }
}
