import {Elements} from '../../dom/Elements.ts';
import {MemoryCollapse} from '../../properties/MemoryCollapse.ts';
import {RowTree} from '../../properties/RowTree.ts';
import type {EntitySession} from '../entities/EntitySession.ts';
import type {ViewContext} from '../workspace/ViewContext.ts';
import type {CollapseMemory} from './CollapseMemory.ts';

/**
 * One property of an entity, anywhere in a custom layout, like Unreal's
 * single property view: its row at `path`, with what's under it. It follows
 * the session; dispose() of it when its view goes.
 *
 *     const engine = new PropertyRow(context, hold.session, 'engine');
 *     card.append(engine.element);
 */
export class PropertyRow {
    public readonly element: HTMLElement;
    private readonly tree: RowTree;

    /** @param collapse Where its collapsed properties are remembered; only while it lives, unless given. */
    public constructor(context: ViewContext, private readonly session: EntitySession, private readonly path: string, collapse: CollapseMemory = new MemoryCollapse()) {
        this.element = Elements.create('div', {classes: ['properties']});
        this.tree = new RowTree(session, {api: context.api, editors: context.properties, entities: context.entities, collapse, filter: null}, () => this.render());
        this.render();
    }

    /** Stops following the session. */
    public dispose(): void {
        this.tree.dispose();
    }

    /** Shows the row, once the session is ready. */
    private render(): void {
        this.tree.clear();
        this.element.replaceChildren(...(this.session.state === 'ready' ? [this.tree.row(this.path)] : []));
    }
}
