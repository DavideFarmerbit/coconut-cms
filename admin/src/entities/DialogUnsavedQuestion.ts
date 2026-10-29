import {Dialog} from '../dom/Dialog.ts';
import type {UnsavedAnswer, UnsavedQuestion} from '../public/entities/UnsavedQuestion.ts';

/** The admin's UnsavedQuestion: a dialog offering Save, Discard and Cancel. */
export class DialogUnsavedQuestion implements UnsavedQuestion {
    public ask(label: string): Promise<UnsavedAnswer> {
        return Dialog.ask<UnsavedAnswer>('Unsaved changes', `${label} has changes that aren't saved.`, [
            {label: 'Save', answer: 'save', primary: true},
            {label: 'Discard', answer: 'discard'},
            {label: 'Cancel', answer: 'cancel'},
        ]);
    }
}
