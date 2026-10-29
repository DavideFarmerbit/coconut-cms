import {Elements} from '../../dom/Elements.ts';
import type {User} from '../../public/auth/User.ts';

/** Told that the user asked to sign out. */
export type SignOutRequested = () => void;

/** The bar over everything: the app's name, then who's signed in and a way to sign out. */
export class MenuBar {
    public readonly element: HTMLElement;

    public constructor(user: User, onSignOut: SignOutRequested) {
        const signOut = Elements.create('button', {classes: ['button', 'button--quiet'], text: 'Sign out', attributes: {type: 'button'}});
        signOut.addEventListener('click', () => onSignOut());

        this.element = Elements.create('header', {classes: ['menubar']}, [
            Elements.create('span', {classes: ['menubar__name'], text: 'Coconut CMS'}),
            Elements.create('div', {classes: ['menubar__user']}, [
                Elements.create('span', {classes: ['muted'], text: user.name}),
                signOut,
            ]),
        ]);
    }
}
