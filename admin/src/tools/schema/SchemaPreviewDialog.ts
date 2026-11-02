import {Elements} from '../../dom/Elements.ts';
import {Modal} from '../../dom/Modal.ts';
import type {PreviewOutcome} from '../../schema/save/PreviewOutcome.ts';
import type {SchemaPreview} from '../../schema/save/SchemaPreview.ts';
import type {SchemaSession} from '../../schema/save/SchemaSession.ts';
import {SchemaReportView} from './SchemaReportView.ts';

/**
 * Saving the schema: the preview, in a modal dialog, then the save it
 * confirms. Modal, since editing while it's open would make it out of
 * date. It shows what keeps the draft from saving, the report to
 * confirm, or that the draft is stale, offering a reload.
 */
export class SchemaPreviewDialog {
    private readonly modal: Modal;
    private settle: (settled: boolean) => void = () => undefined;

    private constructor(private readonly session: SchemaSession) {
        this.modal = new Modal('Save the schema', () => this.close(false), true);
    }

    /**
     * Previews `session`'s draft and lets the admin save it.
     * @return Whether nothing is left unsaved once it closes: saved, dropped by a reload, or nothing to save.
     */
    public static review(session: SchemaSession): Promise<boolean> {
        const dialog = new SchemaPreviewDialog(session);

        return new Promise((resolve) => {
            dialog.settle = resolve;
            void dialog.preview();
        });
    }

    /** Asks for a preview, and shows what it gives. */
    private async preview(): Promise<void> {
        this.busy('Previewing…');
        await this.attempt(async () => this.show(await this.session.preview(), null));
    }

    /** Shows `outcome`, under `note` when there's one to tell. */
    private show(outcome: PreviewOutcome, note: string | null): void {
        const notes = note === null ? [] : [Elements.create('p', {classes: ['schema-report__note'], text: note})];
        switch (outcome.kind) {
            case 'unchanged':
                this.message([...notes, Elements.create('p', {text: 'The draft is as loaded: there\'s nothing to save.'})], true);

                return;
            case 'problems':
                this.message([...notes, Elements.create('p', {text: 'Fix these in the draft first:'}), Elements.create('ul', {classes: ['schema-report__list']}, outcome.problems.map((problem) => Elements.create('li', {text: problem.message})))], false);

                return;
            case 'stale':
                this.stale();

                return;
            case 'previewed':
                this.report(outcome.preview, notes);
        }
    }

    /** Shows `preview`'s report, offering to save it unless it would be refused. */
    private report(preview: SchemaPreview, notes: readonly HTMLElement[]): void {
        this.modal.body.replaceChildren(...notes, ...SchemaReportView.of(preview.report));
        this.modal.setButtons([
            {label: preview.breaks ? 'Accept breaks and save' : 'Save', primary: true, disabled: preview.refused, action: () => void this.save(preview, notes)},
            {label: 'Cancel', action: () => this.close(false)},
        ]);
    }

    /** Saves `preview`, then closes, or shows why it didn't. */
    private async save(preview: SchemaPreview, notes: readonly HTMLElement[]): Promise<void> {
        this.modal.setButtons([{label: 'Saving…', disabled: true, action: () => undefined}]);
        await this.attempt(async () => {
            const outcome = await this.session.save(preview);
            switch (outcome.kind) {
                case 'saved':
                    this.close(true);

                    return;
                case 'cancelled':
                    this.report(preview, [...notes, Elements.create('p', {classes: ['schema-report__note'], text: 'Nothing was saved: an entity with unsaved changes on these types wasn\'t settled.'})]);

                    return;
                case 'stale':
                    this.stale();

                    return;
                case 'refused':
                    this.show(outcome.outcome, 'The server refused the save: the content changed since the preview. This is the preview again.');
            }
        });
    }

    /** Tells that the schema was saved by someone else since the draft loaded, offering a reload. */
    private stale(): void {
        this.modal.body.replaceChildren(Elements.create('p', {text: 'Someone saved the schema since your draft loaded. It can\'t be saved on top: reload it, dropping your edits.'}));
        this.modal.setButtons([
            {label: 'Reload, dropping my edits', action: () => void this.attempt(async () => {
                await this.session.reload();
                this.close(true);
            })},
            {label: 'Cancel', action: () => this.close(false)},
        ]);
    }

    /** Shows `content` with only a Close button, closing as `settled`. */
    private message(content: readonly HTMLElement[], settled: boolean): void {
        this.modal.body.replaceChildren(...content);
        this.modal.setButtons([{label: 'Close', primary: true, action: () => this.close(settled)}]);
    }

    /** Shows `text` while waiting, with no button to press. */
    private busy(text: string): void {
        this.modal.body.replaceChildren(Elements.create('p', {classes: ['muted'], text}));
        this.modal.setButtons([]);
    }

    /** Runs `work`, showing the error it fails with. */
    private async attempt(work: () => Promise<void>): Promise<void> {
        try {
            await work();
        } catch (failure) {
            this.message([Elements.create('p', {text: failure instanceof Error ? failure.message : String(failure)})], false);
        }
    }

    /** Closes the dialog, resolving review() with `settled`. */
    private close(settled: boolean): void {
        this.modal.close();
        this.settle(settled);
    }
}
