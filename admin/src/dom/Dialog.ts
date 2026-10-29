import {Elements} from './Elements.ts';

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
            const overlay = Elements.create('div', {classes: ['dialog-overlay']});
            const close = (answer: Answer): void => {
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();
                resolve(answer);
            };
            const buttons = choices.map((choice) => {
                const button = Elements.create('button', {classes: ['button', ...(choice.primary === true ? ['button--primary'] : [])], text: choice.label, attributes: {type: 'button'}});
                button.addEventListener('click', () => close(choice.answer));

                return button;
            });
            const onKey = (event: KeyboardEvent): void => {
                const last = choices[choices.length - 1];
                if (event.key === 'Escape' && last !== undefined) {
                    event.stopPropagation();
                    close(last.answer);
                }
            };

            overlay.append(Elements.create('div', {classes: ['dialog', 'island'], attributes: {role: 'dialog', 'aria-modal': 'true'}}, [
                Elements.create('h2', {classes: ['dialog__title'], text: title}),
                Elements.create('p', {text: message}),
                Elements.create('div', {classes: ['dialog__buttons']}, buttons),
            ]));
            document.body.append(overlay);
            document.addEventListener('keydown', onKey, true);
            (buttons[choices.findIndex((choice) => choice.primary === true)] ?? buttons[0])?.focus();
        });
    }
}
