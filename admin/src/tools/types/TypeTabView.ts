import {Elements} from '../../dom/Elements.ts';
import type {JsonValue} from '../../public/workspace/Json.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';
import type {FieldDescription, TypeDescription, ValueDescription} from './TypeDescription.ts';

/**
 * A type's tab: what it extends, what the user may do with its entities,
 * and its own fields, each with its kind and access. Opened with
 * `{type: identifier}`.
 */
export class TypeTabView implements PanelView {
    public readonly element: HTMLElement;

    public constructor(private readonly context: ViewContext) {
        this.element = Elements.create('div', {classes: ['type-view']});
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
        const params = this.context.params;
        const identifier = typeof params === 'object' && params !== null && !Array.isArray(params)
            ? (params as {readonly [key: string]: JsonValue})['type']
            : undefined;
        if (typeof identifier !== 'string') {
            this.element.replaceChildren(Elements.create('p', {classes: ['muted'], text: 'This tab names no type.'}));

            return;
        }

        try {
            this.show(await this.context.api.call<TypeDescription>('coconut-cms:types.get', {type: identifier}));
        } catch (failure) {
            this.element.replaceChildren(Elements.create('p', {classes: ['muted'], text: failure instanceof Error ? failure.message : String(failure)}));
        }
    }

    /** Shows `type`. */
    private show(type: TypeDescription): void {
        const allowed = (Object.entries(type.may) as [string, boolean][]).filter(([, may]) => may).map(([action]) => action);
        const rows = Object.entries(type.fields).map(([name, field]) => TypeTabView.row(name, field));

        this.element.replaceChildren(
            Elements.create('h1', {classes: ['type-view__title'], text: type.identifier}),
            Elements.create('p', {classes: ['muted'], text: [
                type.parent === null ? 'A root type' : `Extends ${type.parent}`,
                allowed.length > 0 ? `you may ${allowed.join(', ')}` : 'you may do nothing with its entities',
            ].join(' · ')}),
            rows.length === 0
                ? Elements.create('p', {classes: ['muted'], text: 'No fields of its own.'})
                : Elements.create('table', {classes: ['data-table']}, [
                    Elements.create('thead', {}, [Elements.create('tr', {}, ['Field', 'Kind', 'Holds', 'Access'].map((title) => Elements.create('th', {text: title})))]),
                    Elements.create('tbody', {}, rows),
                ]),
        );
    }

    /** The table row of field `name`. */
    private static row(name: string, field: FieldDescription): HTMLElement {
        const access = field.read ? (field.write ? 'read, write' : 'read only') : 'hidden';

        return Elements.create('tr', {}, [
            Elements.create('td', {classes: ['data-table__name'], text: name}),
            Elements.create('td', {text: TypeTabView.kind(field.value)}),
            Elements.create('td', {classes: ['muted'], text: TypeTabView.holds(field.value)}),
            Elements.create('td', {classes: field.read ? [] : ['muted'], text: access}),
        ]);
    }

    /** `value`'s kind, in words. */
    private static kind(value: ValueDescription): string {
        const kinds: Record<ValueDescription['kind'], string> = {
            scalar: 'Scalar',
            value_object: 'Value object',
            embed: 'Embed',
            reference: 'Reference',
            owning_reference: 'Owned',
            collection: 'Collection',
        };

        return kinds[value.kind];
    }

    /** What `value` holds, in words: a scalar's type, a target, a Collection's items. */
    private static holds(value: ValueDescription): string {
        const nullable = value.nullable ? ', or null' : '';
        switch (value.kind) {
            case 'scalar':
                return value.enumValues !== null ? `one of ${value.enumValues.join(', ')}${nullable}` : `${value.scalarType ?? ''}${nullable}`;
            case 'collection':
                return value.item === null ? '' : `${TypeTabView.kind(value.item).toLowerCase()} items: ${TypeTabView.holds(value.item)}`;
            case 'value_object':
                return `a value${nullable}`;
            default:
                return `${value.target ?? ''}${nullable}`;
        }
    }
}
