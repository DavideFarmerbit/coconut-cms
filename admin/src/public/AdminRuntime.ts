import {DialogUnsavedQuestion} from '../entities/DialogUnsavedQuestion.ts';
import type {ApiClient} from './api/ApiClient.ts';
import {Auth} from './auth/Auth.ts';
import type {User} from './auth/User.ts';
import {EntityStore} from './entities/EntityStore.ts';
import {PropertyEditors} from './properties/PropertyEditors.ts';
import {TypeStore} from './types/TypeStore.ts';
import type {TabTypeId, ToolId} from './workspace/Ids.ts';
import {Registry} from './workspace/Registry.ts';
import type {TabTypeDefinition} from './workspace/TabTypeDefinition.ts';
import type {ToolDefinition} from './workspace/ToolDefinition.ts';

/**
 * The running admin, as plugins reach it: its tools and tab types, the API
 * client it calls the backend with, who's signed in, and their types and
 * entities. One instance, `admin`, exported by the
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

    /** Who's signed in; set once the app has started. */
    private authentication: Auth | undefined;

    /** The types, for whoever is signed in; undefined while nobody is. */
    private typeStore: TypeStore | undefined;

    /** The entities, for whoever is signed in; undefined while nobody is. */
    private store: EntityStore | undefined;

    /** The client every call to the backend goes through. Throws before the app started. */
    public get api(): ApiClient {
        if (this.client === undefined) {
            throw new Error('The admin hasn\'t started: there is no API client yet.');
        }

        return this.client;
    }

    /** The entities the admin works with, and their sessions. Throws while nobody is signed in. */
    public get entities(): EntityStore {
        if (this.store === undefined) {
            throw new Error('Nobody is signed in: there is no entity store.');
        }

        return this.store;
    }

    /** The entity types the user may read, following their changes. Throws while nobody is signed in. */
    public get types(): TypeStore {
        if (this.typeStore === undefined) {
            throw new Error('Nobody is signed in: there is no type store.');
        }

        return this.typeStore;
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
        // Followed first: the stores start over before any view hears of the user.
        this.authentication.onChange((user) => this.userChanged(user));
    }

    /**
     * Starts the types and the entities over for `user`: what they may read
     * differs. The last user's entity store closes, so the views going let
     * its sessions go without asking; nobody has no stores.
     */
    private userChanged(user: User | null): void {
        const auth = this.auth;
        if (this.store !== undefined) {
            auth.unguard(this.store);
            this.store.close();
        }

        this.typeStore = user === null ? undefined : new TypeStore(this.api);
        this.store = this.typeStore === undefined ? undefined : new EntityStore(this.api, this.typeStore, new DialogUnsavedQuestion());
        if (this.store !== undefined) {
            auth.guard(this.store);
        }
    }
}
