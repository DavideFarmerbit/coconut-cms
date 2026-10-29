import {Elements} from '../../dom/Elements.ts';
import {ViewStateCollapse} from '../../properties/ViewStateCollapse.ts';
import type {EntityHold} from '../entities/EntityHold.ts';
import type {EntitySession} from '../entities/EntitySession.ts';
import type {SessionChange} from '../entities/SessionChange.ts';
import type {ViewContext} from '../workspace/ViewContext.ts';
import {PropertyGrid} from './PropertyGrid.ts';

/**
 * The default view of one entity, for tabs: a header with its label, type
 * and id, an unsaved mark, Save and Revert, then a PropertyGrid of the whole
 * entity. It shows a session it holds: the session loads and saves, other
 * views of the entity share it. Cmd or Ctrl and S saves. Which properties
 * and groups are collapsed is kept in its view's state, per entity type.
 * A tab's view hands its canClose() to the shell, and disposes of it.
 *
 *     this.editor = EntityEditor.edit(context, id);
 *     this.element = this.editor.element;
 */
export class EntityEditor {
    /** The view state's name for the groups closed, by entity type. */
    private static readonly COLLAPSED_GROUPS = 'collapsedGroups';

    /** The view state's name for the properties collapsed, by entity type. */
    private static readonly COLLAPSED_PROPERTIES = 'collapsedProperties';

    public readonly element: HTMLElement;
    private readonly session: EntitySession;
    private readonly title = Elements.create('span');
    private readonly subtitle = Elements.create('p', {classes: ['muted']});
    private readonly entityError = Elements.create('p', {classes: ['entity-editor__error']});
    private readonly save = Elements.create('button', {classes: ['button', 'button--primary', 'entity-editor__save'], text: 'Save', attributes: {type: 'button'}});
    private readonly revert = Elements.create('button', {classes: ['button', 'button--quiet', 'entity-editor__revert'], text: 'Revert', attributes: {type: 'button'}});
    private readonly body = Elements.create('div');
    private grid: PropertyGrid | undefined;
    private readonly listener = (change: SessionChange): void => this.changed(change);

    /** Shows the session `hold` holds; the editor lets the hold go when it closes. */
    public constructor(private readonly context: ViewContext, private readonly hold: EntityHold) {
        this.session = hold.session;
        this.save.addEventListener('click', () => void this.session.save());
        this.revert.addEventListener('click', () => this.session.revert());
        this.element = Elements.create('div', {classes: ['entity-editor']}, [
            Elements.create('header', {classes: ['entity-editor__header']}, [
                Elements.create('div', {classes: ['entity-editor__heading']}, [
                    Elements.create('h1', {classes: ['entity-editor__title']}, [
                        this.title,
                        Elements.create('span', {classes: ['entity-editor__dirty'], text: '●', attributes: {'aria-label': 'Unsaved changes'}}),
                    ]),
                    this.subtitle,
                ]),
                Elements.create('div', {classes: ['entity-editor__actions']}, [this.revert, this.save]),
            ]),
            this.entityError,
            this.body,
        ]);
        this.element.addEventListener('keydown', (event) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 's') {
                event.preventDefault();
                void this.session.save();
            }
        });

        this.session.onChange(this.listener);
        if (this.session.state === 'loading') {
            this.body.replaceChildren(Elements.create('p', {classes: ['muted'], text: 'Loading…'}));
        } else {
            this.showEntity();
        }
    }

    /** An editor of the entity `id`, sharing its session with the entity's other views. */
    public static edit(context: ViewContext, id: string): EntityEditor {
        return new EntityEditor(context, context.entities.edit(id));
    }

    /** An editor of a new entity of `type`, from its defaults: its first save creates it. */
    public static create(context: ViewContext, type: string): EntityEditor {
        return new EntityEditor(context, context.entities.create(type));
    }

    /** Whether it may close: lets its hold go, which asks first when it's the last one on unsaved changes. */
    public canClose(): Promise<boolean> {
        return this.hold.release();
    }

    /** Stops following the session, and lets its hold go when canClose() didn't. */
    public dispose(): void {
        this.session.offChange(this.listener);
        this.grid?.dispose();
        void this.hold.release();
    }

    /** Follows the session: the grid shows its rows, the editor its header. */
    private changed(change: SessionChange): void {
        if (change.kind === 'reloaded' && this.grid === undefined && this.session.state !== 'loading') {
            this.showEntity();
        }
        this.showState();
        if (change.kind === 'reloaded') {
            this.context.setTitle(this.session.label);
        }
    }

    /** Shows the entity: its grid, once the session is read. */
    private showEntity(): void {
        const session = this.session;
        if (session.state === 'ready') {
            const state = this.context.state;
            this.grid = new PropertyGrid(this.context, session, {
                properties: new ViewStateCollapse(state, EntityEditor.COLLAPSED_PROPERTIES, session.type),
                groups: new ViewStateCollapse(state, EntityEditor.COLLAPSED_GROUPS, session.type),
            });
            this.body.replaceChildren(this.grid.element);
        } else {
            this.body.replaceChildren(Elements.create('p', {classes: ['muted'], text: session.failure}));
        }
        this.showState();
        this.context.setTitle(session.label);
    }

    /** Shows the header: label, type and id, unsaved changes, saving, the error about the whole entity; tells the view. */
    private showState(): void {
        const session = this.session;
        const dirty = session.isDirty;
        this.title.textContent = session.label;
        this.subtitle.textContent = `${session.type} · ${session.id ?? 'not saved yet'}`;
        this.entityError.textContent = session.entityErrorMessage;
        this.entityError.hidden = session.entityErrorMessage === '';
        this.element.classList.toggle('is-dirty', dirty);
        this.save.textContent = session.isSaving ? 'Saving…' : 'Save';
        this.save.disabled = !dirty || session.isSaving || session.state !== 'ready';
        this.revert.disabled = !dirty || session.isSaving || session.state !== 'ready' || session.id === null;
        this.context.markDirty(dirty);
    }
}
