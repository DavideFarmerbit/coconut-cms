import {TypeStore} from '../types/TypeStore.ts';
import {tabTypeId} from '../workspace/Ids.ts';
import type {TabOpening} from '../workspace/TabOpening.ts';
import type {ViewContext} from '../workspace/ViewContext.ts';
import {EditorRegistry} from './EditorRegistry.ts';

/** The entry to open: its id, its type, and its tab's title. */
export interface EntryToOpen {
    readonly id: string;
    readonly type: string;
    readonly title: string;
}

/**
 * Which editor opens what, as Unreal's asset definitions say which editor
 * opens an asset: `admin.editors`, reached in a view as `context.editors`.
 * A plugin registers a tab type for the entries of a type, and one for the
 * types extending it; what has none opens in the admin's generic tab.
 *
 *     admin.editors.instances.register({type: 'game:item', tab: ITEM_TAB});
 *     context.editors.openInstance(context, {id, type, title}, 'reuse');
 *
 * An entry's tab is opened with `{id, type}`, a type's with `{type}`.
 */
export class Editors {
    /** The admin's generic entry editor: the entity's properties. */
    public static readonly ENTITY_TAB = tabTypeId('coconut-cms:entity');

    /** The admin's generic type tab: the type inspector. */
    public static readonly TYPE_TAB = tabTypeId('coconut-cms:type');

    /** The editors of entries, by type. An entry whose type has one is an asset. */
    public readonly instances = new EditorRegistry('entries');

    /** The editors of types, by the type they extend. */
    public readonly types = new EditorRegistry('types');

    /** Opens `entry` in the editor registered for its type, or the generic one, as `opening` says. */
    public openInstance(context: ViewContext, entry: EntryToOpen, opening: TabOpening): void {
        const tab = this.instances.of(entry.type, context.service(TypeStore).catalog)?.tab ?? Editors.ENTITY_TAB;
        context.workspace.openTab(tab, entry.title, {id: entry.id, type: entry.type}, entry.id, opening);
    }

    /** Opens the type `identifier` in the editor registered for what it extends, or the generic one, as `opening` says. */
    public openType(context: ViewContext, identifier: string, opening: TabOpening): void {
        const tab = this.types.of(identifier, context.service(TypeStore).catalog)?.tab ?? Editors.TYPE_TAB;
        context.workspace.openTab(tab, identifier, {type: identifier}, identifier, opening);
    }
}
