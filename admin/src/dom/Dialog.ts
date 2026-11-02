import {Elements} from './Elements.ts';
import {Modal} from './Modal.ts';

/** One button of a Dialog: its label, what choosing it answers, and whether it's the one Enter picks. */
export interface DialogChoice<Answer> {
    readonly label: string;
    readonly answer: Answer;
    readonly primary?: boolean;
}

/**
 * A modal question with a few answers, over a dimmed page: "Save the
 * changes?". Escape answers with the last choice, the one meant to cancel.
 */
export class Dialog {
    /** Asks `message`, titled `title`; resolves to the answer of the choice picked. */
    public static ask<Answer>(title: string, message: string, choices: readonly DialogChoice<Answer>[]): Promise<Answer> {
        return new Promise((resolve) => {
            const answer = (choice: DialogChoice<Answer> | undefined): void => {
                if (choice !== undefined) {
                    modal.close();
                    resolve(choice.answer);
                }
            };
            const modal = new Modal(title, () => answer(choices[choices.length - 1]));
            modal.body.append(Elements.create('p', {text: message}));
            modal.setButtons(choices.map((choice) => ({label: choice.label, primary: choice.primary === true, action: () => answer(choice)})));
        });
    }
}
