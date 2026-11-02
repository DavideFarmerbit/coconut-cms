import type {ApiClient} from '../../public/api/ApiClient.ts';
import {ApiFailure} from '../../public/api/ApiFailure.ts';
import {ErrorCode} from '../../public/api/ErrorCode.ts';
import type {GuardPlace} from '../../public/auth/GuardPlace.ts';
import type {SignOutGuard} from '../../public/auth/SignOutGuard.ts';
import type {EntityStore} from '../../public/entities/EntityStore.ts';
import {DraftWriter} from '../draft/DraftWriter.ts';
import {SchemaDraft} from '../draft/SchemaDraft.ts';
import type {SchemaDefinitionList} from '../SchemaDefinitions.ts';
import type {SchemaReport} from '../SchemaReport.ts';
import type {PreviewOutcome} from './PreviewOutcome.ts';
import type {SaveOutcome} from './SaveOutcome.ts';
import {SchemaPreview} from './SchemaPreview.ts';
import type {UnsavedSchemaQuestion} from './UnsavedSchemaQuestion.ts';

/** Told that the session's draft was replaced: loaded again, after a save or a reload. */
export type DraftReplacedListener = (draft: SchemaDraft) => void;

/**
 * The schema editor's one draft, from loading to saving. A save always goes
 * through a preview: preview() writes the draft and asks the server what it
 * would do; save() confirms that preview. Once saved, the types and the
 * draft load again, its history cleared: what it was is no longer stored.
 * A draft built on an old revision can't save: reload() drops its edits.
 *
 * As a sign-out guard, it asks about unsaved edits before the entity
 * store's guard: saving the schema settles the entity editors it affects.
 *
 *     const session = await SchemaSession.load(api, admin.entities, question);
 *     admin.auth.guard(session, SchemaSession.GUARD);
 */
export class SchemaSession implements SignOutGuard {
    /** Its place among the sign-out guards. */
    public static readonly GUARD: GuardPlace = {name: 'coconut-cms:schema', before: ['coconut-cms:entities']};

    private readonly listeners: DraftReplacedListener[] = [];

    private constructor(
        private readonly api: ApiClient,
        private readonly entities: EntityStore,
        private readonly question: UnsavedSchemaQuestion,
        private current: SchemaDraft,
    ) {
    }

    /**
     * A session over the schema as schema.get gives it now.
     * @param entities Its entity editors are settled before a save, and its types loaded again after.
     * @param question Asked about unsaved edits before signing out.
     */
    public static async load(api: ApiClient, entities: EntityStore, question: UnsavedSchemaQuestion): Promise<SchemaSession> {
        return new SchemaSession(api, entities, question, await SchemaSession.read(api));
    }

    /** The draft every view of the schema edits. Replaced after a save or a reload: follow onReplaced(). */
    public get draft(): SchemaDraft {
        return this.current;
    }

    /** Whether the draft holds edits a save would send. */
    public get hasUnsaved(): boolean {
        const written = DraftWriter.write(this.current);

        return written.operations.length > 0 || written.problems.length > 0;
    }

    /** Tells `listener` each time the draft is replaced. */
    public onReplaced(listener: DraftReplacedListener): void {
        this.listeners.push(listener);
    }

    /** Stops telling `listener`. */
    public offReplaced(listener: DraftReplacedListener): void {
        const index = this.listeners.indexOf(listener);
        if (index >= 0) {
            this.listeners.splice(index, 1);
        }
    }

    /**
     * Writes the draft and asks the server what saving it would do, showing
     * up to `samples` entries it changes. Sends nothing while the draft has
     * problems.
     */
    public async preview(samples = 3): Promise<PreviewOutcome> {
        const save = DraftWriter.write(this.current);
        if (save.problems.length > 0) {
            return {kind: 'problems', problems: save.problems};
        }
        if (save.operations.length === 0) {
            return {kind: 'unchanged'};
        }

        try {
            const report = await this.api.call<SchemaReport>('coconut-cms:schema.preview', {revision: save.revision, operations: save.operations, samples});

            return {kind: 'previewed', preview: new SchemaPreview(save, report)};
        } catch (failure) {
            if (failure instanceof ApiFailure && failure.code === ErrorCode.StaleRevision) {
                return {kind: 'stale'};
            }
            throw failure;
        }
    }

    /**
     * Saves what `preview` previewed, accepting the breaks it listed. The
     * entity editors with unsaved changes on the types it changes are
     * settled first. Once saved, the types and the draft load again.
     */
    public async save(preview: SchemaPreview): Promise<SaveOutcome> {
        if (!await this.entities.settle(this.changedTypes())) {
            return {kind: 'cancelled'};
        }

        let report: SchemaReport;
        try {
            report = await this.api.call<SchemaReport>('coconut-cms:schema.save', {
                revision: preview.save.revision,
                operations: preview.save.operations,
                acceptBreaks: preview.breaks,
            });
        } catch (failure) {
            if (failure instanceof ApiFailure && failure.code === ErrorCode.StaleRevision) {
                return {kind: 'stale'};
            }
            if (failure instanceof ApiFailure && failure.code === ErrorCode.SchemaSaveRefused) {
                return {kind: 'refused', outcome: await this.preview(preview.report.samples.length)};
            }
            throw failure;
        }

        await this.entities.types.reload();
        await this.reload();

        return {kind: 'saved', report};
    }

    /** Loads the draft again from the server, dropping its edits and its history. */
    public async reload(): Promise<void> {
        this.current = await SchemaSession.read(this.api);
        for (const listener of [...this.listeners]) {
            listener(this.current);
        }
    }

    /** Asks about unsaved edits: Review and save, Discard or Cancel. Signing out goes ahead once they're saved or dropped. */
    public async allowsSignOut(): Promise<boolean> {
        if (!this.hasUnsaved) {
            return true;
        }

        const answer = await this.question.ask();
        if (answer === 'review') {
            return this.question.review(this);
        }
        if (answer === 'discard') {
            await this.reload();
        }

        return answer === 'discard';
    }

    /**
     * The stored types the draft changes, as the entity store knows them:
     * each type changed, and the type each changed field was declared on.
     * A type the draft creates holds no entries yet.
     */
    private changedTypes(): string[] {
        const {types, fields} = this.current.everything();
        const changed = new Set<string>();
        for (const type of types) {
            if (type.was !== null && type.now !== type.was) {
                changed.add(type.was.identifier);
            }
        }
        for (const field of fields) {
            if (field.was !== null && field.now !== field.was) {
                changed.add(this.current.type(field.was.owner)!.was!.identifier);
            }
        }

        return [...changed];
    }

    /** The draft of the schema as schema.get gives it now. */
    private static async read(api: ApiClient): Promise<SchemaDraft> {
        return SchemaDraft.load(await api.call<SchemaDefinitionList>('coconut-cms:schema.get'));
    }
}
