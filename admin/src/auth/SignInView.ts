import type {View} from '../app/View.ts';
import {Elements} from '../dom/Elements.ts';
import type {ApiClient} from '../public/api/ApiClient.ts';
import {ApiFailure} from '../public/api/ApiFailure.ts';
import {ErrorCode} from '../public/api/ErrorCode.ts';
import type {Me} from '../public/auth/Me.ts';
import type {User} from '../public/auth/User.ts';

/** Told who signed in. */
export type SignedIn = (user: User) => void;

/**
 * The sign-in form: an email or username, a password, and "remember me".
 * Signs in through coconut-cms:auth.signIn, and tells why it couldn't.
 */
export class SignInView implements View {
    public readonly element: HTMLElement;
    private readonly login: HTMLInputElement;
    private readonly password: HTMLInputElement;
    private readonly remember: HTMLInputElement;
    private readonly submit: HTMLButtonElement;
    private readonly message: HTMLElement;

    /** @param onSignedIn Told who signed in, once they have. */
    public constructor(
        private readonly api: ApiClient,
        private readonly onSignedIn: SignedIn,
    ) {
        this.login = Elements.create('input', {classes: ['field__input'], attributes: {name: 'login', autocomplete: 'username', required: ''}});
        this.password = Elements.create('input', {classes: ['field__input'], attributes: {name: 'password', type: 'password', autocomplete: 'current-password', required: ''}});
        this.remember = Elements.create('input', {attributes: {name: 'remember', type: 'checkbox'}});
        this.submit = Elements.create('button', {classes: ['button', 'button--primary'], text: 'Sign in', attributes: {type: 'submit'}});
        this.message = Elements.create('p', {classes: ['sign-in__message'], attributes: {role: 'alert'}});

        const form = Elements.create('form', {classes: ['sign-in__form']}, [
            Elements.create('h1', {classes: ['sign-in__title'], text: 'Coconut CMS'}),
            Elements.create('label', {classes: ['field']}, [Elements.create('span', {classes: ['field__label'], text: 'Email or username'}), this.login]),
            Elements.create('label', {classes: ['field']}, [Elements.create('span', {classes: ['field__label'], text: 'Password'}), this.password]),
            Elements.create('label', {classes: ['check']}, [this.remember, Elements.create('span', {text: 'Remember me'})]),
            this.message,
            this.submit,
        ]);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            void this.signIn();
        });

        this.element = Elements.create('main', {classes: ['sign-in']}, [Elements.create('div', {classes: ['island', 'sign-in__island']}, [form])]);
    }

    public shown(): void {
        this.login.focus();
    }

    /** Signs in with what the form holds, telling the result. */
    private async signIn(): Promise<void> {
        this.setBusy(true);
        this.message.textContent = '';
        try {
            const me = await this.api.call<Me>('coconut-cms:auth.signIn', {
                login: this.login.value,
                password: this.password.value,
                remember: this.remember.checked,
            });
            if (me.user === null) {
                throw new Error('The backend signed nobody in.');
            }

            this.onSignedIn(me.user);
        } catch (failure) {
            this.message.textContent = SignInView.explain(failure);
            this.password.select();
        } finally {
            this.setBusy(false);
        }
    }

    /** Disables the form while a sign-in runs. */
    private setBusy(busy: boolean): void {
        this.submit.disabled = busy;
        this.element.classList.toggle('is-busy', busy);
    }

    /** What to tell the person signing in about `failure`. */
    private static explain(failure: unknown): string {
        if (!(failure instanceof ApiFailure)) {
            return String(failure);
        }

        switch (failure.code) {
            case ErrorCode.SignInFailed:
                return 'Those credentials don\'t sign anyone in.';
            case ErrorCode.TooManyRequests:
                return 'Too many attempts: wait a while, then try again.';
            case ErrorCode.CsrfFailed:
                return 'This page is stale: reload it, then sign in.';
            default:
                return failure.message;
        }
    }
}
