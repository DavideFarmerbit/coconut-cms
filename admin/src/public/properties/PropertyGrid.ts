import {Elements} from '../../dom/Elements.ts';
import {ActionIcon} from '../../icons/ActionIcon.ts';
import {MemoryCollapse} from '../../properties/MemoryCollapse.ts';
import {RowTree} from '../../properties/RowTree.ts';
import type {EntitySession} from '../entities/EntitySession.ts';
import type {CatalogField} from '../types/TypeCatalog.ts';
import type {ViewContext} from '../workspace/ViewContext.ts';
import type {CollapseMemory} from './CollapseMemory.ts';

/** How a PropertyGrid shows its session. */
export interface PropertyGridOptions {
    /** Where the properties collapsed are remembered; only while the grid lives, unless given. */
    readonly properties?: CollapseMemory;
    /** Where the groups closed are remembered, by type; only while the grid lives, unless given. */
    readonly groups?: CollapseMemory;
}

/**
 * An entity's properties, like Unreal's details view: every field, grouped
 * by the type declaring it, the root first, as collapsible categories. A
 * type with no ancestor shows no group header. It follows the session row by
 * row; dispose() of it when its view goes.
 *
 *     const grid = new PropertyGrid(context, hold.session);
 *     element.append(grid.element);
 */
export class PropertyGrid {
    public readonly element: HTMLElement;
    private readonly tree: RowTree;
    private readonly groups: CollapseMemory;

    public constructor(context: ViewContext, private readonly session: EntitySession, options: PropertyGridOptions = {}) {
        this.groups = options.groups ?? new MemoryCollapse();
        this.element = Elements.create('div', {classes: ['property-grid']});
        this.tree = new RowTree(session, {
            api: context.api,
            editors: context.properties,
            entities: context.entities,
            collapse: options.properties ?? new MemoryCollapse(),
        }, () => this.render());
        this.render();
    }

    /** Stops following the session. */
    public dispose(): void {
        this.tree.dispose();
    }

    /** Shows the whole entity, or where its session stands. */
    private render(): void {
        const session = this.session;
        if (session.state !== 'ready' || session.types === undefined) {
            this.element.replaceChildren(Elements.create('p', {classes: ['muted'], text: session.state === 'failed' ? session.failure : 'Loading…'}));

            return;
        }

        this.tree.clear();
        const groups = PropertyGrid.byDeclaringType(session.types.fieldsOf(session.type));
        this.element.replaceChildren(groups.length <= 1
            ? this.tree.rootFields(groups[0]?.fields ?? [])
            : Elements.create('div', {classes: ['property-grid__groups']}, groups.map(({type, fields}) => this.group(type, fields))));
    }

    /** A collapsible category of `type`'s fields, closed as remembered. */
    private group(type: string, fields: readonly CatalogField[]): HTMLElement {
        const group = Elements.create('details', {classes: ['property-group']}, [
            Elements.create('summary', {classes: ['property-group__title']}, [ActionIcon.svg('expand'), Elements.create('span', {text: type})]),
            this.tree.rootFields(fields),
        ]);
        group.open = !this.groups.isCollapsed(type);
        group.addEventListener('toggle', () => this.groups.setCollapsed(type, !group.open));

        return group;
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
