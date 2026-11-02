import type {EditHistory} from '../../public/edits/EditHistory.ts';
import {EntityEditor} from '../../public/properties/EntityEditor.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';
import type {ViewContext} from '../../public/workspace/ViewContext.ts';

/**
 * The admin's generic entry editor: one entity's properties, editable,
 * through the EntityEditor. It opens an entry whose type no plugin's
 * editor covers. Opened with `{id, type}`.
 */
export class EntityTabView implements PanelView {
    public readonly element: HTMLElement;
    private readonly editor: EntityEditor;

    public constructor(context: ViewContext) {
        const params = context.params;
        const id = typeof params === 'object' && params !== null && !Array.isArray(params) ? (params as Readonly<Record<string, unknown>>)['id'] : undefined;
        this.editor = EntityEditor.edit(context, typeof id === 'string' ? id : '');
        this.element = this.editor.element;
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
        this.editor.dispose();
    }

    /** Lets the entity's session go, asking about unsaved changes when this was its last view. */
    public canClose(): Promise<boolean> {
        return this.editor.canClose();
    }

    /** The entity's changes, undoable. */
    public get history(): EditHistory {
        return this.editor.history;
    }
}
