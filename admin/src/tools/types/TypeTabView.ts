import {ContextMenu} from '../../dom/ContextMenu.ts';
import {Elements} from '../../dom/Elements.ts';
import type {TypeCatalog} from '../../public/types/TypeCatalog.ts';
import type {FieldDescription, TypeDescription} from '../../public/types/TypeDescription.ts';
import type {JsonValue} from '../../public/workspace/Json.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import {AccessIcon} from '../../icons/AccessIcon.ts';
import {TypeIcon} from '../../icons/TypeIcon.ts';
import {ValueWords} from '../../icons/ValueWords.ts';
import {TypeLink} from './TypeLink.ts';
import {TypesTool} from './TypesTool.ts';

/**
 * The type inspector, one type per tab: what it extends, its subtypes in a
 * dropdown, what the user may do with its entities, then its own fields and
 * each ancestor's below, so the whole type shows with where each field comes
 * from. Every type named is a link. Opened with `{type: identifier}`.
 */
export class TypeTabView implements PanelView {
    public readonly element: HTMLElement;

    public constructor(private readonly context: ViewContext) {
        this.element = Elements.create('div', {classes: ['inspector']});
        void this.load();
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
    }

    /** Shows the type the tab was opened with. */
    private async load(): Promise<void> {
        const identifier = TypeTabView.typeIn(this.context.params);
        try {
            const catalog = await this.context.typeCatalog();
            const type = identifier === null ? undefined : catalog.get(identifier);
            if (type === undefined) {
                this.showMessage(identifier === null ? 'This tab names no type.' : `There is no type "${identifier}" you may read.`);

                return;
            }

            this.show(type, catalog);
        } catch (failure) {
            this.showMessage(failure instanceof Error ? failure.message : String(failure));
        }
    }

    /** Shows `type`, its ancestors' fields from `catalog`. */
    private show(type: TypeDescription, catalog: TypeCatalog): void {
        const sections = [
            this.section([Elements.create('span', {text: 'Fields'})], type, 'No fields of its own.'),
            ...catalog.ancestorsOf(type.identifier).map((ancestor) => this.section(
                [Elements.create('span', {text: 'From '}), TypeLink.create(ancestor.identifier, ancestor.identifier, this.context.workspace)],
                ancestor,
                'No fields.',
            )),
        ];

        this.element.replaceChildren(this.header(type, catalog), ...sections);
    }

    /** The header: the identifier, the subtypes dropdown, the parent, the rights on its entities. */
    private header(type: TypeDescription, catalog: TypeCatalog): HTMLElement {
        const heading = Elements.create('div', {classes: ['inspector__heading']}, [Elements.create('h1', {classes: ['inspector__title'], text: type.identifier})]);
        const subtypes = catalog.subtypesOf(type.identifier);
        if (subtypes.length > 0) {
            const dropdown = Elements.create('button', {classes: ['button', 'button--quiet'], text: `Subtypes (${subtypes.length}) ▾`, attributes: {type: 'button'}});
            dropdown.addEventListener('click', () => ContextMenu.below(dropdown, subtypes.map((subtype) => ({
                label: subtype.identifier,
                run: () => TypesTool.open(this.context.workspace, subtype.identifier, 'reuse'),
            }))));
            heading.append(dropdown);
        }

        return Elements.create('header', {classes: ['inspector__header']}, [
            heading,
            Elements.create('p', {classes: ['inspector__meta', 'muted']}, type.parent === null
                ? ['A root type']
                : ['Extends ', TypeLink.create(type.parent, type.parent, this.context.workspace)]),
            Elements.create('div', {classes: ['inspector__access']}, [
                AccessIcon.of('read', type.may.read, true),
                AccessIcon.of('create', type.may.create, true),
                AccessIcon.of('update', type.may.update, true),
                AccessIcon.of('delete', type.may.delete, true),
            ]),
        ]);
    }

    /** A section titled `title`, listing `type`'s own fields; `empty` when it has none. */
    private section(title: readonly (Node | string)[], type: TypeDescription, empty: string): HTMLElement {
        const rows = Object.entries(type.fields).map(([name, field]) => this.row(name, field));

        return Elements.create('section', {classes: ['field-section']}, [
            Elements.create('h2', {classes: ['field-section__title']}, title),
            rows.length === 0
                ? Elements.create('p', {classes: ['muted'], text: empty})
                : Elements.create('table', {classes: ['field-table']}, [Elements.create('tbody', {}, rows)]),
        ]);
    }

    /** The row of field `name`: its icon, its name, its type, and the user's rights on it. */
    private row(name: string, field: FieldDescription): HTMLElement {
        const target = ValueWords.targetOf(field.value);
        const typeName = ValueWords.nameOf(field.value);
        const typeElement = target === null
            ? Elements.create('span', {classes: ['muted'], text: typeName})
            : TypeLink.create(target, typeName, this.context.workspace);

        return Elements.create('tr', {classes: field.read ? [] : ['is-hidden']}, [
            Elements.create('td', {classes: ['field-table__icon']}, [TypeIcon.of(field.value)]),
            Elements.create('td', {classes: ['field-table__name'], text: name}),
            Elements.create('td', {classes: ['field-table__type']}, [typeElement]),
            Elements.create('td', {classes: ['field-table__access']}, [AccessIcon.of('read', field.read), AccessIcon.of('write', field.write)]),
        ]);
    }

    /** Shows only `message`. */
    private showMessage(message: string): void {
        this.element.replaceChildren(Elements.create('p', {classes: ['muted'], text: message}));
    }

    /** The type identifier `params` holds; null for none. */
    private static typeIn(params: JsonValue): string | null {
        if (typeof params !== 'object' || params === null || Array.isArray(params)) {
            return null;
        }

        const type = (params as {readonly [key: string]: JsonValue})['type'];

        return typeof type === 'string' ? type : null;
    }
}
