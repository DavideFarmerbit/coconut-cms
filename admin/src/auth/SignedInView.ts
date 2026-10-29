import type {View} from '../app/View.ts';
import {Elements} from '../dom/Elements.ts';
import type {ApiClient} from '../public/api/ApiClient.ts';
import type {User} from '../public/auth/User.ts';

/** Told that the user signed out. */
export type SignedOut = () => void;

/**
 * What a signed-in user sees until the shell exists: who they are, and a
 * way to sign out. The shell replaces it.
 */
export class SignedInView implements View {
    public readonly element: HTMLElement;
    private readonly signOutButton: HTMLButtonElement;

    /** @param onSignedOut Told once the sign-out went through. */
    public constructor(
        private readonly api: ApiClient,
        user: User,
        private readonly onSignedOut: SignedOut,
    ) {
        this.signOutButton = Elements.create('button', {classes: ['button'], text: 'Sign out', attributes: {type: 'button'}});
        this.signOutButton.addEventListener('click', () => void this.signOut());

        this.element = Elements.create('main', {classes: ['sign-in']}, [
            Elements.create('div', {classes: ['island', 'sign-in__island']}, [
                Elements.create('h1', {classes: ['sign-in__title'], text: 'Coconut CMS'}),
                Elements.create('p', {text: `Signed in as ${user.name}${user.roles.length > 0 ? ` (${user.roles.join(', ')})` : ''}.`}),
                Elements.create('p', {classes: ['muted'], text: 'The workspace comes next.'}),
                this.signOutButton,
            ]),
        ]);
    }

    public shown(): void {
        this.signOutButton.focus();
    }

    /** Signs out, then tells. */
    private async signOut(): Promise<void> {
        this.signOutButton.disabled = true;
        try {
            await this.api.call('coconut-cms:auth.signOut');
            this.onSignedOut();
        } finally {
            this.signOutButton.disabled = false;
        }
    }
}
