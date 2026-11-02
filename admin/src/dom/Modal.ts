import {Elements} from './Elements.ts';

/** One button of a Modal: its label, what clicking it does, and how it shows. */
export interface ModalButton {
    readonly label: string;
    readonly action: () => void;
    /** The one Enter picks, and the one focused. */
    readonly primary?: boolean;
    readonly disabled?: boolean;
}

/**
 * A modal box over a dimmed page: a title, a body its owner fills and
 * changes, and a row of buttons it sets again whenever they change.
 * Escape runs the topmost one's `onEscape`. Nothing behind it takes clicks
 * while it's open; one opened over it, a question asked while saving,
 * comes first.
 *
 *     const modal = new Modal('Save the schema', () => modal.close());
 *     modal.body.append(report);
 *     modal.setButtons([{label: 'Save', primary: true, action: save}, {label: 'Cancel', action: () => modal.close()}]);
 */
export class Modal {
    /** Every modal open, the topmost last: only it answers Escape. */
    private static readonly open: Modal[] = [];

    /** What it shows: its owner fills it. */
    public readonly body: HTMLElement;
    private readonly overlay: HTMLElement;
    private readonly buttons: HTMLElement;
    private readonly onKey = (event: KeyboardEvent): void => {
        if (event.key === 'Escape' && Modal.open[Modal.open.length - 1] === this) {
            event.stopPropagation();
            this.onEscape();
        }
    };

    /** Opens it, titled `title`; Escape runs `onEscape`. */
    public constructor(title: string, private readonly onEscape: () => void, wide = false) {
        this.body = Elements.create('div', {classes: ['dialog__body']});
        this.buttons = Elements.create('div', {classes: ['dialog__buttons']});
        this.overlay = Elements.create('div', {classes: ['dialog-overlay']}, [
            Elements.create('div', {classes: ['dialog', 'island', ...(wide ? ['dialog--wide'] : [])], attributes: {role: 'dialog', 'aria-modal': 'true'}}, [
                Elements.create('h2', {classes: ['dialog__title'], text: title}),
                this.body,
                this.buttons,
            ]),
        ]);
        document.body.append(this.overlay);
        document.addEventListener('keydown', this.onKey, true);
        Modal.open.push(this);
    }

    /** Shows `buttons`, in order, focusing the primary one, or the first. */
    public setButtons(buttons: readonly ModalButton[]): void {
        const elements = buttons.map((each) => {
            const button = Elements.create('button', {classes: ['button', ...(each.primary === true ? ['button--primary'] : [])], text: each.label, attributes: {type: 'button'}});
            button.disabled = each.disabled === true;
            button.addEventListener('click', () => each.action());

            return button;
        });
        this.buttons.replaceChildren(...elements);
        (elements[buttons.findIndex((each) => each.primary === true && each.disabled !== true)] ?? elements.find((each) => !each.disabled))?.focus();
    }

    /** Closes it for good. */
    public close(): void {
        document.removeEventListener('keydown', this.onKey, true);
        this.overlay.remove();
        const index = Modal.open.indexOf(this);
        if (index >= 0) {
            Modal.open.splice(index, 1);
        }
    }
}
