import {Dialog} from '../../dom/Dialog.ts';
import type {SchemaSession} from '../../schema/save/SchemaSession.ts';
import type {UnsavedSchemaAnswer, UnsavedSchemaQuestion} from '../../schema/save/UnsavedSchemaQuestion.ts';
import {SchemaPreviewDialog} from './SchemaPreviewDialog.ts';

/** The admin's UnsavedSchemaQuestion: a dialog offering Review and save, Discard and Cancel; the preview dialog to review. */
export class DialogUnsavedSchemaQuestion implements UnsavedSchemaQuestion {
    public ask(): Promise<UnsavedSchemaAnswer> {
        return Dialog.ask<UnsavedSchemaAnswer>('Unsaved schema changes', 'The types have changes that aren\'t saved.', [
            {label: 'Review and save', answer: 'review', primary: true},
            {label: 'Discard', answer: 'discard'},
            {label: 'Cancel', answer: 'cancel'},
        ]);
    }

    public review(session: SchemaSession): Promise<boolean> {
        return SchemaPreviewDialog.review(session);
    }
}
