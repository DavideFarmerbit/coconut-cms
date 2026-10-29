import type {AdminRuntime} from '../../public/AdminRuntime.ts';
import {tabTypeId, toolId} from '../../public/workspace/Ids.ts';
import {TypesView} from './TypesView.ts';
import {TypeTabView} from './TypeTabView.ts';

/**
 * The Types tool: a panel listing every entity type the user may read, each
 * opening a tab describing its fields. Registered the way a plugin registers
 * its tools.
 */
export class TypesTool {
    public static readonly TOOL = toolId('coconut-cms:types');
    public static readonly TAB = tabTypeId('coconut-cms:type');

    /** A stack of layers, drawn with currentColor. */
    private static readonly ICON = '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"><path d="M8 2 14 5 8 8 2 5Z"/><path d="M2 8l6 3 6-3"/><path d="M2 11l6 3 6-3"/></svg>';

    /** Registers the tool and its tab type with `runtime`. */
    public static register(runtime: AdminRuntime): void {
        runtime.tools.register({
            id: TypesTool.TOOL,
            title: 'Types',
            icon: TypesTool.ICON,
            placement: {side: 'left', section: 'topTop'},
            view: TypesView,
        });
        runtime.tabTypes.register({id: TypesTool.TAB, view: TypeTabView});
    }
}
