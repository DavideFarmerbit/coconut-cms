import {ContextMenu} from '../../dom/ContextMenu.ts';
import {Elements} from '../../dom/Elements.ts';
import {Tooltips} from '../../dom/Tooltips.ts';
import {EditHistory} from '../../public/edits/EditHistory.ts';
import {TypeCatalog} from '../../public/types/TypeCatalog.ts';
import {TypeStore} from '../../public/types/TypeStore.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import type {SchemaDraft} from '../../schema/draft/SchemaDraft.ts';
import type {TypeRecord} from '../../schema/draft/TypeRecord.ts';
import type {SchemaSession} from '../../schema/save/SchemaSession.ts';
import {NewTypeDialog} from '../schema/NewTypeDialog.ts';
import {SchemaEditing} from '../schema/SchemaEditing.ts';
import {TypeLink} from './TypeLink.ts';

/**
 * The Types panel: every entity type, in a collapsible group per
 * namespace, each by its key. Opening one marks it. Which type is marked
 * and which groups are closed are remembered.
 *
 * To a schema editor, it lists the schema draft: a type created, renamed
 * or deleted shows so, and "New type" creates one. To anyone else, the
 * types they may read, as the type store has them. Either way it shows
 * them again when they change. Undo here undoes the draft's last edit.
 */
export class TypesView implements PanelView {
    /** The view state's name for the type marked. */
    private static readonly SELECTED = 'selected';

    /** The view state's name for the namespaces closed. */
    private static readonly COLLAPSED = 'collapsed';

    public readonly element: HTMLElement;
    private readonly types: TypeStore;
    private session: SchemaSession | null = null;
    private draft: SchemaDraft | null = null;
    /** The history while no draft is loaded: nothing to undo. */
    private readonly idle = new EditHistory();
    private readonly listener = (): void => this.show();
    private readonly replaced = (draft: SchemaDraft): void => this.follow(draft);

    public constructor(private readonly context: ViewContext) {
        this.element = Elements.create('div', {classes: ['types-view']}, [TypesView.note('Loading…')]);
        this.types = context.service(TypeStore);
        // Without the schema, or failing to load it, the types read are shown.
        const session = context.service(SchemaEditing).session().catch(() => null);
        Promise.all([this.types.ready, session]).then(([, loaded]) => {
            this.session = loaded;
            if (loaded === null) {
                this.types.onChange(this.listener);
                this.show();
            } else {
                loaded.onReplaced(this.replaced);
                this.follow(loaded.draft);
            }
        }, (failure: unknown) => {
            this.element.replaceChildren(TypesView.note(failure instanceof Error ? failure.message : String(failure)));
        });
    }

    /** The draft's history: undo here undoes its last edit, whichever view made it. Empty without a draft. */
    public get history(): EditHistory {
        return this.draft?.history ?? this.idle;
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
        this.types.offChange(this.listener);
        this.draft?.offChange(this.listener);
        this.session?.offReplaced(this.replaced);
    }

    /** Follows `draft`'s edits from now on, in place of the one before. */
    private follow(draft: SchemaDraft): void {
        this.draft?.offChange(this.listener);
        this.draft = draft;
        draft.onChange(this.listener);
        this.show();
    }

    /** Lists the types now: the draft's, or the catalog's. */
    private show(): void {
        if (this.draft === null) {
            const groups = this.types.catalog.byNamespace();
            this.element.replaceChildren(...[...groups.entries()].map(([namespace, types]) => this.group(namespace, types.map((type) => this.item(type.identifier, TypeCatalog.keyOf(type.identifier))))));
        } else {
            this.showDraft(this.draft);
        }
        this.markSelected();
    }

    /** Lists `draft`'s types, deleted ones included, with "New type" above. */
    private showDraft(draft: SchemaDraft): void {
        const byNamespace = new Map<string, TypeRecord[]>();
        const records = draft.everything().types.filter((type) => type.now !== null || type.was !== null);
        for (const type of [...records].sort((a, b) => TypesView.identifierOf(a).localeCompare(TypesView.identifierOf(b)))) {
            const namespace = TypeCatalog.namespaceOf(TypesView.identifierOf(type));
            byNamespace.set(namespace, [...byNamespace.get(namespace) ?? [], type]);
        }

        const create = Elements.create('button', {classes: ['button', 'button--quiet'], text: 'New type…', attributes: {type: 'button'}});
        create.addEventListener('click', () => void NewTypeDialog.open(draft));
        this.element.replaceChildren(
            Elements.create('div', {classes: ['types-view__toolbar']}, [create]),
            ...[...byNamespace.entries()].map(([namespace, types]) => this.group(namespace, types.map((type) => this.draftItem(type)), () => void NewTypeDialog.open(draft, namespace))),
        );
    }

    /** The collapsible group of `namespace`, holding `items`; its right-click offers a new type there, when `create` is given. */
    private group(namespace: string, items: readonly HTMLElement[], create?: () => void): HTMLElement {
        const summary = Elements.create('summary', {classes: ['type-group__name']}, [
            Elements.create('span', {text: namespace}),
            Elements.create('span', {classes: ['type-group__count'], text: String(items.length)}),
        ]);
        if (create !== undefined) {
            summary.addEventListener('contextmenu', (event) => {
                event.preventDefault();
                ContextMenu.show(event.clientX, event.clientY, [{label: `New type in ${namespace}…`, run: create}]);
            });
        }
        const group = Elements.create('details', {classes: ['type-group']}, [
            summary,
            Elements.create('ul', {classes: ['list'], attributes: {role: 'listbox', 'aria-label': namespace}}, items),
        ]);
        group.open = !this.collapsed().includes(namespace);
        group.addEventListener('toggle', () => {
            const others = this.collapsed().filter((each) => each !== namespace);
            this.context.state.set(TypesView.COLLAPSED, group.open ? others : [...others, namespace]);
        });

        return group;
    }

    /** The draft's entry for `type`: marked created, renamed or deleted. A deleted one opens nothing. */
    private draftItem(type: TypeRecord): HTMLElement {
        const identifier = TypesView.identifierOf(type);
        if (type.now === null) {
            return Elements.create('li', {classes: ['list__item', 'is-deleted'], text: TypeCatalog.keyOf(identifier), attributes: Tooltips.attributes('Deleted: the save drops it.', 'top')});
        }

        const item = this.item(identifier, TypeCatalog.keyOf(identifier));
        if (type.was === null) {
            item.classList.add('is-created');
            item.append(Elements.create('span', {classes: ['list__badge'], text: 'new'}));
        } else if (type.was.identifier !== identifier) {
            item.classList.add('is-renamed');
            item.append(Elements.create('span', {classes: ['list__badge'], text: 'renamed', attributes: Tooltips.attributes(`Renamed from ${type.was.identifier}.`, 'top')}));
        }

        return item;
    }

    /** The list's entry opening the type `identifier`, reading `label`. */
    private item(identifier: string, label: string): HTMLElement {
        const item = Elements.create('li', {
            classes: ['list__item'],
            attributes: {role: 'option', tabindex: '0', 'data-type': identifier},
        }, [Elements.create('span', {text: label})]);
        TypeLink.attach(item, identifier, this.context.workspace, (opened) => {
            this.context.state.set(TypesView.SELECTED, opened);
            this.markSelected();
        });
        item.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                item.click();
            }
        });

        return item;
    }

    /** The namespaces closed, as remembered. */
    private collapsed(): readonly string[] {
        const collapsed = this.context.state.get(TypesView.COLLAPSED);

        return Array.isArray(collapsed) ? collapsed.filter((each): each is string => typeof each === 'string') : [];
    }

    /** Marks the type remembered as selected. */
    private markSelected(): void {
        const selected = this.context.state.get(TypesView.SELECTED);
        for (const item of this.element.querySelectorAll<HTMLElement>('[data-type]')) {
            const isSelected = item.dataset['type'] === selected;
            item.classList.toggle('is-selected', isSelected);
            item.setAttribute('aria-selected', String(isSelected));
        }
    }

    /** `type`'s identifier now; the one it had, for a type deleted. */
    private static identifierOf(type: TypeRecord): string {
        return (type.now ?? type.was)!.identifier;
    }

    /** A muted line in the panel: why it's empty. */
    private static note(text: string): HTMLElement {
        return Elements.create('p', {classes: ['list__note', 'muted'], text});
    }
}
