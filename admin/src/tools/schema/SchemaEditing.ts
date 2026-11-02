import type {ApiClient} from '../../public/api/ApiClient.ts';
import {ApiFailure} from '../../public/api/ApiFailure.ts';
import {ErrorCode} from '../../public/api/ErrorCode.ts';
import type {Auth} from '../../public/auth/Auth.ts';
import {EntityStore} from '../../public/entities/EntityStore.ts';
import type {Disposing} from '../../public/services/Disposing.ts';
import type {ServiceContext} from '../../public/services/ServiceContext.ts';
import type {UnsavedWork} from '../../public/services/UnsavedWork.ts';
import {SchemaSession} from '../../schema/save/SchemaSession.ts';
import type {UnsavedSchemaQuestion} from '../../schema/save/UnsavedSchemaQuestion.ts';
import {SchemaListings} from '../../schema/SchemaListings.ts';
import {DialogUnsavedSchemaQuestion} from './DialogUnsavedSchemaQuestion.ts';

/**
 * The schema editor's service: the one SchemaSession every view of it
 * shares, and the listings of what fits a field. The session loads on the
 * first ask, and guards signing out from then on. A user without the
 * schema-editor role gets none: the server refuses them the schema, and
 * the Types tool stays read-only.
 *
 *     const session = await context.service(SchemaEditing).session();
 *     if (session !== null) { ...edit session.draft... }
 */
export class SchemaEditing implements Disposing, UnsavedWork {
    private loading: Promise<SchemaSession | null> | null = null;
    private loaded: SchemaSession | null = null;
    private listing: Promise<SchemaListings> | null = null;
    private disposed = false;

    public constructor(
        private readonly api: ApiClient,
        private readonly auth: Auth,
        private readonly entities: EntityStore,
        private readonly question: UnsavedSchemaQuestion,
    ) {
    }

    /** The user's, as a service: it asks with the admin's dialogs. */
    public static create(context: ServiceContext): SchemaEditing {
        return new SchemaEditing(context.api, context.auth, context.service(EntityStore), new DialogUnsavedSchemaQuestion());
    }

    /** The shared session, loaded on the first ask; null when the user may not edit the schema. */
    public session(): Promise<SchemaSession | null> {
        this.loading ??= this.load();

        return this.loading;
    }

    /** What fits a field: converters, initializers, rules, enums and value objects. Loaded once. */
    public listings(): Promise<SchemaListings> {
        this.listing ??= SchemaListings.load(this.api);

        return this.listing;
    }

    /** Whether the draft holds edits a save would send. */
    public get hasUnsaved(): boolean {
        return this.loaded?.hasUnsaved ?? false;
    }

    /** Stops guarding signing out: its user signed out. */
    public dispose(): void {
        this.disposed = true;
        if (this.loaded !== null) {
            this.auth.unguard(this.loaded);
        }
    }

    /** Loads the session and guards signing out with it. A failure other than being refused is asked again next time. */
    private async load(): Promise<SchemaSession | null> {
        try {
            const session = await SchemaSession.load(this.api, this.entities, this.question);
            if (!this.disposed) {
                this.auth.guard(session, SchemaSession.GUARD);
                this.loaded = session;
            }

            return session;
        } catch (failure) {
            if (failure instanceof ApiFailure && failure.code === ErrorCode.PermissionDenied) {
                return null;
            }
            this.loading = null;
            throw failure;
        }
    }
}
