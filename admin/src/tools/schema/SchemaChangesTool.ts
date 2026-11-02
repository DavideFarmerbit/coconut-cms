import type {AdminRuntime} from '../../public/AdminRuntime.ts';
import {toolId} from '../../public/workspace/Ids.ts';
import {SchemaChangesView} from './SchemaChangesView.ts';

/**
 * The Schema changes tool: a panel at the bottom listing what the schema
 * draft changes, with Save and Discard, as an IDE lists the changes to
 * commit. Registered the way a plugin registers its tools.
 */
export class SchemaChangesTool {
    public static readonly TOOL = toolId('coconut-cms:schema-changes');

    /** A list with a dot by each line, drawn with currentColor. */
    private static readonly ICON = '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><circle cx="3" cy="4" r="1" fill="currentColor" stroke="none"/><circle cx="3" cy="8" r="1" fill="currentColor" stroke="none"/><circle cx="3" cy="12" r="1" fill="currentColor" stroke="none"/><path d="M6 4h8M6 8h8M6 12h5"/></svg>';

    /** Registers the tool with `runtime`. */
    public static register(runtime: AdminRuntime): void {
        runtime.tools.register({
            id: SchemaChangesTool.TOOL,
            title: 'Schema changes',
            icon: SchemaChangesTool.ICON,
            placement: {side: 'left', section: 'bottom'},
            view: SchemaChangesView,
        });
    }
}
