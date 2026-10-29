import {Dialog} from '../../dom/Dialog.ts';
import {Elements} from '../../dom/Elements.ts';
import {ActionIcon} from '../../icons/ActionIcon.ts';
import {EntityDraft} from '../../properties/EntityDraft.ts';
import {PropertyRows} from '../../properties/PropertyRows.ts';
import {ReferenceLabels} from '../../properties/ReferenceLabels.ts';
import {ApiFailure} from '../api/ApiFailure.ts';
import {ErrorCode} from '../api/ErrorCode.ts';
import type {CatalogField, TypeCatalog} from '../types/TypeCatalog.ts';
import type {ViewContext} from '../workspace/ViewContext.ts';
import {EntityLabels} from './EntityLabels.ts';
import {PropertyPath} from './PropertyPath.ts';

/** What coconut-cms:entities.get and entities.update give. */
interface EntityData {
    readonly type: string;
    readonly id: string;
    readonly values: Readonly<Record<string, unknown>>;
}

/**
 * One entity's properties, editable, and saved explicitly: a header with
 * its label, type and id, Save and Revert; then its fields, grouped by the
 * type declaring them, the root first. Edits wait until Save sends them in
 * one coconut-cms:entities.update; errors show at their properties. Put its
 * element in any view; a tab's view also hands canClose() to the shell.
 *
 *     const editor = new EntityEditor(context, id);
 *     this.element = editor.element;
 */
export class EntityEditor {
    /** The view state's name for the groups closed, by entity type. */
    private static readonly COLLAPSED = 'collapsedGroups';

    /** The view state's name for the properties collapsed, by entity type. */
    private static readonly COLLAPSED_PROPERTIES = 'collapsedProperties';

    public readonly element: HTMLElement;
    private readonly errors = new Map<string, string>();
    private topError = '';
    private catalog: TypeCatalog | undefined;
    private entity: EntityData | undefined;
    private draft: EntityDraft | undefined;
    /** The rows shown now. */
    private rows: PropertyRows | undefined;
    private saving = false;

    public constructor(
        private readonly context: ViewContext,
        private readonly id: string,
    ) {
        this.element = Elements.create('div', {classes: ['entity-editor']}, [Elements.create('p', {classes: ['muted'], text: 'Loading…'})]);
        this.element.addEventListener('keydown', (event) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 's') {
                event.preventDefault();
                void this.save();
            }
        });
        void this.load();
    }

    /** Sends every change, then shows what was stored. @return Whether it saved: false shows why. */
    public async save(): Promise<boolean> {
        const draft = this.draft;
        const catalog = this.catalog;
        if (draft === undefined || catalog === undefined || !draft.isDirty || this.saving) {
            return draft !== undefined && !draft.isDirty;
        }

        this.saving = true;
        this.render();
        try {
            const saved = await this.context.api.call<EntityData>('coconut-cms:entities.update', {id: this.id, changes: draft.changes(catalog)});
            this.entity = saved;
            this.errors.clear();
            this.topError = '';
            draft.accept(saved.values);
            this.context.setTitle(this.label());

            return true;
        } catch (failure) {
            this.showFailure(failure);

            return false;
        } finally {
            this.saving = false;
            this.render();
        }
    }

    /** Drops every change. */
    public revert(): void {
        this.errors.clear();
        this.topError = '';
        this.draft?.revert();
        this.render();
    }

    /** Whether it may close: with unsaved changes, asks to save, discard or cancel. */
    public async canClose(): Promise<boolean> {
        if (this.draft === undefined || !this.draft.isDirty) {
            return true;
        }

        const answer = await Dialog.ask('Unsaved changes', `${this.label()} has changes that aren't saved.`, [
            {label: 'Save', answer: 'save', primary: true},
            {label: 'Discard', answer: 'discard'},
            {label: 'Cancel', answer: 'cancel'},
        ] as const);

        return answer === 'save' ? this.save() : answer === 'discard';
    }

    /** Reads the entity and the types, then shows it. */
    private async load(): Promise<void> {
        try {
            const [entity, catalog] = await Promise.all([
                this.context.api.call<EntityData>('coconut-cms:entities.get', {id: this.id}),
                this.context.typeCatalog(),
            ]);
            this.entity = entity;
            this.catalog = catalog;
            this.draft = new EntityDraft(entity.values);
            this.draft.onChange(() => this.showState());
            this.context.setTitle(this.label());
            this.render();
        } catch (failure) {
            this.element.replaceChildren(Elements.create('p', {classes: ['muted'], text: failure instanceof Error ? failure.message : String(failure)}));
        }
    }

    /** Shows the whole entity from the working copy. */
    private render(): void {
        const entity = this.entity;
        const catalog = this.catalog;
        const draft = this.draft;
        if (entity === undefined || catalog === undefined || draft === undefined) {
            return;
        }

        const rows = this.rows = new PropertyRows(
            draft,
            catalog,
            this.context.api,
            this.context.properties,
            new ReferenceLabels(this.context.api, catalog),
            this.errors,
            new Set(this.remembered(EntityEditor.COLLAPSED_PROPERTIES)),
            (path, collapsed) => this.remember(EntityEditor.COLLAPSED_PROPERTIES, path, collapsed),
            () => this.render(),
        );
        const groups = EntityEditor.byDeclaringType(catalog.fieldsOf(entity.type));
        const body = groups.length <= 1
            ? rows.fields(entity.type, draft.values, groups[0]?.fields ?? [], null, PropertyPath.TRAVERSE)
            : Elements.create('div', {classes: ['entity-editor__groups']}, groups.map(({type, fields}) => this.group(type, rows.fields(entity.type, draft.values, fields, null, PropertyPath.TRAVERSE))));

        this.element.replaceChildren(this.header(), ...(this.topError === '' ? [] : [Elements.create('p', {classes: ['entity-editor__error'], text: this.topError})]), body);
        this.showState();
    }

    /** The header: label, type, id, the unsaved mark, Save and Revert. */
    private header(): HTMLElement {
        const save = Elements.create('button', {classes: ['button', 'button--primary', 'entity-editor__save'], text: this.saving ? 'Saving…' : 'Save', attributes: {type: 'button'}});
        save.addEventListener('click', () => void this.save());
        const revert = Elements.create('button', {classes: ['button', 'button--quiet', 'entity-editor__revert'], text: 'Revert', attributes: {type: 'button'}});
        revert.addEventListener('click', () => this.revert());

        return Elements.create('header', {classes: ['entity-editor__header']}, [
            Elements.create('div', {classes: ['entity-editor__heading']}, [
                Elements.create('h1', {classes: ['entity-editor__title']}, [
                    Elements.create('span', {text: this.label()}),
                    Elements.create('span', {classes: ['entity-editor__dirty'], text: '●', attributes: {'aria-label': 'Unsaved changes'}}),
                ]),
                Elements.create('p', {classes: ['muted'], text: `${this.entity?.type ?? ''} · ${this.id}`}),
            ]),
            Elements.create('div', {classes: ['entity-editor__actions']}, [revert, save]),
        ]);
    }

    /** A collapsible category of `type`'s fields, closed as remembered. */
    private group(type: string, fields: HTMLElement): HTMLElement {
        const group = Elements.create('details', {classes: ['property-group']}, [
            Elements.create('summary', {classes: ['property-group__title']}, [ActionIcon.svg('expand'), Elements.create('span', {text: type})]),
            fields,
        ]);
        group.open = !this.remembered(EntityEditor.COLLAPSED).includes(type);
        group.addEventListener('toggle', () => this.remember(EntityEditor.COLLAPSED, type, !group.open));

        return group;
    }

    /** What's remembered under `name` for this entity's type: the groups or properties closed. */
    private remembered(name: string): readonly string[] {
        const state = this.context.state.get(name);
        const byType = typeof state === 'object' && state !== null && !Array.isArray(state) ? state as Record<string, unknown> : {};
        const closed = byType[this.entity?.type ?? ''];

        return Array.isArray(closed) ? closed.filter((each): each is string => typeof each === 'string') : [];
    }

    /** Remembers under `name`, for this entity's type, that `key` is closed, or not. */
    private remember(name: string, key: string, closed: boolean): void {
        const state = this.context.state.get(name);
        const byType = typeof state === 'object' && state !== null && !Array.isArray(state) ? state as Record<string, readonly string[]> : {};
        const others = this.remembered(name).filter((each) => each !== key);
        this.context.state.set(name, {...byType, [this.entity?.type ?? '']: closed ? [...others, key] : others});
    }

    /** Shows whether there are unsaved changes, and which properties changed; tells the view. */
    private showState(): void {
        const dirty = this.draft?.isDirty ?? false;
        this.element.classList.toggle('is-dirty', dirty);
        for (const button of this.element.querySelectorAll<HTMLButtonElement>('.entity-editor__save, .entity-editor__revert')) {
            button.disabled = !dirty || this.saving;
        }

        this.rows?.refresh();
        this.context.markDirty(dirty);
    }

    /** Puts `failure`'s errors at their properties, or at the top. */
    private showFailure(failure: unknown): void {
        this.errors.clear();
        this.topError = '';
        if (!(failure instanceof ApiFailure)) {
            this.topError = String(failure);

            return;
        }

        const errors = failure.details['errors'];
        const byPath = typeof errors === 'object' && errors !== null ? errors as Record<string, unknown> : {};
        if (failure.code !== ErrorCode.InvalidInput && failure.code !== ErrorCode.ValidationFailed) {
            this.topError = failure.message;

            return;
        }

        for (const [path, messages] of Object.entries(byPath)) {
            const message = Array.isArray(messages) ? messages.join(' ') : String(messages);
            const property = path.startsWith('changes.') ? path.slice('changes.'.length) : path;
            if (property === '' || property === 'changes') {
                this.topError = [this.topError, message].filter((each) => each !== '').join(' ');
            } else {
                this.errors.set(property, message);
            }
        }
    }

    /** What the entity is called. */
    private label(): string {
        const entity = this.entity;

        return entity === undefined || this.catalog === undefined ? this.id : EntityLabels.of(this.catalog, entity.type, entity.id, this.draft?.values ?? entity.values);
    }

    /** `fields` grouped by the type declaring them, in their order: the root's first. */
    private static byDeclaringType(fields: readonly CatalogField[]): {type: string; fields: CatalogField[]}[] {
        const groups: {type: string; fields: CatalogField[]}[] = [];
        for (const field of fields) {
            const last = groups[groups.length - 1];
            if (last !== undefined && last.type === field.declaredBy) {
                last.fields.push(field);
            } else {
                groups.push({type: field.declaredBy, fields: [field]});
            }
        }

        return groups;
    }
}
