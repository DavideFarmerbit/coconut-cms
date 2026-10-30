import {Dialog} from '../dom/Dialog.ts';
import type {UnsavedAnswer, UnsavedQuestion} from '../public/entities/UnsavedQuestion.ts';

/** The admin's UnsavedQuestion: a dialog offering Save, Discard and Cancel, or the same for all. */
export class DialogUnsavedQuestion implements UnsavedQuestion {
    public ask(label: string): Promise<UnsavedAnswer> {
        return Dialog.ask<UnsavedAnswer>('Unsaved changes', `${label} has changes that aren't saved.`, [
            {label: 'Save', answer: 'save', primary: true},
            {label: 'Discard', answer: 'discard'},
            {label: 'Cancel', answer: 'cancel'},
        ]);
    }

    public askAll(labels: readonly string[]): Promise<UnsavedAnswer> {
        const count = labels.length === 1 ? '1 entity has' : `${labels.length} entities have`;

        return Dialog.ask<UnsavedAnswer>('Unsaved changes', `${count} changes that aren't saved: ${labels.join(', ')}.`, [
            {label: 'Save all', answer: 'save', primary: true},
            {label: 'Discard all', answer: 'discard'},
            {label: 'Cancel', answer: 'cancel'},
        ]);
    }
}
