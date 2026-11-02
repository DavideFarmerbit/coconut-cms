import {ChoicePicker} from '../../dom/ChoicePicker.ts';
import {Elements} from '../../dom/Elements.ts';
import {LocalChoices} from '../../dom/LocalChoices.ts';
import {Modal} from '../../dom/Modal.ts';
import type {TypeId} from '../../schema/draft/DraftIds.ts';
import {EditRefused} from '../../schema/draft/EditRefused.ts';
import type {SchemaDraft} from '../../schema/draft/SchemaDraft.ts';

/**
 * Creating a type in the schema draft: its "namespace:key" identifier and
 * the type it extends, or none for a root. What the draft refuses shows
 * under the form, and the dialog stays open to fix it.
 */
export class NewTypeDialog {
    private readonly modal: Modal;
    private readonly identifier: HTMLInputElement;
    private readonly parentButton: HTMLButtonElement;
    private readonly error: HTMLElement;
    private parent: TypeId | null = null;
    private settle: (created: TypeId | null) => void = () => undefined;

    private constructor(private readonly draft: SchemaDraft, namespace: string) {
        this.modal = new Modal('New type', () => this.close(null));
        this.identifier = Elements.create('input', {classes: ['field__input'], attributes: {placeholder: 'namespace:key', spellcheck: 'false'}});
        this.identifier.value = namespace === '' ? '' : `${namespace}:`;
        this.identifier.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                this.create();
            }
        });
        this.parentButton = Elements.create('button', {classes: ['field__input', 'field__input--dropdown'], attributes: {type: 'button'}});
        this.parentButton.addEventListener('click', () => this.pickParent());
        this.error = Elements.create('p', {classes: ['schema-report__note'], attributes: {role: 'alert'}});
        this.showParent();
        this.modal.body.append(
            NewTypeDialog.labelled('Identifier', this.identifier),
            NewTypeDialog.labelled('Extends', this.parentButton),
            this.error,
        );
        this.modal.setButtons([
            {label: 'Create', primary: true, action: () => this.create()},
            {label: 'Cancel', action: () => this.close(null)},
        ]);
        this.identifier.focus();
    }

    /**
     * Asks for a new type, its identifier started with `namespace`, and
     * creates it in `draft`: its id, or null when cancelled.
     */
    public static open(draft: SchemaDraft, namespace = ''): Promise<TypeId | null> {
        const dialog = new NewTypeDialog(draft, namespace);

        return new Promise((resolve) => {
            dialog.settle = resolve;
        });
    }

    /** Creates the type, or shows why the draft refuses it. */
    private create(): void {
        try {
            this.close(this.draft.createType(this.identifier.value.trim(), this.parent));
        } catch (refused) {
            if (!(refused instanceof EditRefused)) {
                throw refused;
            }
            this.error.textContent = refused.message;
        }
    }

    /** Opens the picker of the types a new one may extend. */
    private pickParent(): void {
        const extensible = this.draft.types().filter((type) => type.now!.editorExtensible);
        ChoicePicker.open<TypeId | null>(this.parentButton, new LocalChoices(extensible.map((type) => ({key: type.id, label: type.now!.identifier, value: type.id}))), {
            placeholder: 'Search types',
            pinned: [{key: 'none', label: 'None: a root type', value: null}],
            current: this.parent ?? 'none',
            picked: (parent) => {
                this.parent = parent;
                this.showParent();
            },
        });
    }

    /** Shows the parent picked on its button. */
    private showParent(): void {
        this.parentButton.textContent = this.parent === null ? 'None: a root type' : this.draft.type(this.parent)?.now?.identifier ?? '';
    }

    /** Closes the dialog, giving `created`. */
    private close(created: TypeId | null): void {
        this.modal.close();
        this.settle(created);
    }

    /** `control` under its `label`. */
    private static labelled(label: string, control: HTMLElement): HTMLElement {
        return Elements.create('label', {classes: ['field']}, [Elements.create('span', {classes: ['field__label'], text: label}), control]);
    }
}
