import {Dialog} from '../../dom/Dialog.ts';
import {Elements} from '../../dom/Elements.ts';
import {EditHistory} from '../../public/edits/EditHistory.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import {DraftChanges} from '../../schema/draft/DraftChanges.ts';
import {DraftWriter} from '../../schema/draft/DraftWriter.ts';
import type {SaveProblem} from '../../schema/draft/SaveProblem.ts';
import type {SchemaDraft} from '../../schema/draft/SchemaDraft.ts';
import type {SchemaSession} from '../../schema/save/SchemaSession.ts';
import {TypeLink} from '../types/TypeLink.ts';
import {SchemaEditing} from './SchemaEditing.ts';
import {SchemaPreviewDialog} from './SchemaPreviewDialog.ts';

/**
 * The Schema changes panel: what the draft changes, type by type, in
 * words, and what keeps it from saving. Save opens the preview; Discard
 * drops every edit. It follows each edit, and the draft loaded again after
 * a save. Undo here undoes the draft's last edit.
 */
export class SchemaChangesView implements PanelView {
    public readonly element: HTMLElement;
    private session: SchemaSession | null = null;
    private draft: SchemaDraft | null = null;
    /** The history while no draft is loaded: nothing to undo. */
    private readonly idle = new EditHistory();
    private readonly edited = (): void => this.render();
    private readonly replaced = (draft: SchemaDraft): void => this.follow(draft);

    public constructor(private readonly context: ViewContext) {
        this.element = Elements.create('div', {classes: ['schema-changes']}, [SchemaChangesView.note('Loading…')]);
        context.service(SchemaEditing).session().then((session) => {
            this.session = session;
            if (session === null) {
                this.element.replaceChildren(SchemaChangesView.note('Only a schema editor changes types.'));

                return;
            }
            session.onReplaced(this.replaced);
            this.follow(session.draft);
        }, (failure: unknown) => this.element.replaceChildren(SchemaChangesView.note(failure instanceof Error ? failure.message : String(failure))));
    }

    /** The draft's history: undo here undoes its last edit, whichever view made it. Empty until it loads. */
    public get history(): EditHistory {
        return this.draft?.history ?? this.idle;
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
        this.draft?.offChange(this.edited);
        this.session?.offReplaced(this.replaced);
    }

    /** Follows `draft`'s edits from now on, in place of the one before. */
    private follow(draft: SchemaDraft): void {
        this.draft?.offChange(this.edited);
        this.draft = draft;
        draft.onChange(this.edited);
        this.render();
    }

    /** Shows the draft's changes and problems now. */
    private render(): void {
        const draft = this.draft;
        if (draft === null) {
            return;
        }

        const changes = DraftChanges.of(draft);
        const problems = DraftWriter.write(draft).problems;
        const toolbar = Elements.create('div', {classes: ['schema-changes__toolbar']}, [
            this.button('Save…', changes.length === 0, true, () => void this.save()),
            this.button('Discard', changes.length === 0, false, () => void this.discard()),
        ]);
        if (changes.length === 0) {
            this.element.replaceChildren(toolbar, SchemaChangesView.note('No changes: the types are as saved.'));

            return;
        }

        this.element.replaceChildren(
            toolbar,
            ...(problems.length === 0 ? [] : [this.problems(draft, problems)]),
            ...changes.map((each) => {
                const type = draft.type(each.type);
                const title = type?.now === null || type === undefined
                    ? Elements.create('span', {classes: ['schema-changes__deleted'], text: each.title})
                    : TypeLink.create(each.title, each.title, this.context.workspace);

                return Elements.create('section', {classes: ['schema-changes__type']}, [
                    Elements.create('h3', {classes: ['schema-changes__title']}, [title]),
                    Elements.create('ul', {classes: ['schema-changes__list']}, each.changes.map((change) => Elements.create('li', {text: change}))),
                ]);
            }),
        );
    }

    /** What keeps the draft from saving, each on the type or field it's about. */
    private problems(draft: SchemaDraft, problems: readonly SaveProblem[]): HTMLElement {
        return Elements.create('section', {classes: ['schema-changes__type', 'schema-changes__type--problems']}, [
            Elements.create('h3', {classes: ['schema-changes__title'], text: 'To fix before saving'}),
            Elements.create('ul', {classes: ['schema-changes__list']}, problems.map((problem) => {
                const field = problem.field === null ? undefined : draft.field(problem.field)?.now ?? undefined;
                const owner = field === undefined ? problem.type : field.owner;
                const where = owner === null ? '' : `${draft.type(owner)?.now?.identifier ?? ''}: `;

                return Elements.create('li', {text: `${where}${problem.message}`});
            })),
        ]);
    }

    /** Opens the preview, which saves on confirming. */
    private async save(): Promise<void> {
        if (this.session !== null) {
            await SchemaPreviewDialog.review(this.session);
        }
    }

    /** Drops every edit, once confirmed. */
    private async discard(): Promise<void> {
        const answer = await Dialog.ask<'discard' | 'cancel'>('Discard schema changes', 'Every change to the types made since the last save will be lost.', [
            {label: 'Discard', answer: 'discard', primary: true},
            {label: 'Cancel', answer: 'cancel'},
        ]);
        if (answer === 'discard') {
            await this.session?.reload();
        }
    }

    /** A toolbar button. */
    private button(label: string, disabled: boolean, primary: boolean, action: () => void): HTMLButtonElement {
        const button = Elements.create('button', {classes: ['button', primary ? 'button--primary' : 'button--quiet'], text: label, attributes: {type: 'button'}});
        button.disabled = disabled;
        button.addEventListener('click', action);

        return button;
    }

    /** A muted line: why there's nothing to list. */
    private static note(text: string): HTMLElement {
        return Elements.create('p', {classes: ['list__note', 'muted'], text});
    }
}
