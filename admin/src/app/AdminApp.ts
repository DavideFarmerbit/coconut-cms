import {SignedInView} from '../auth/SignedInView.ts';
import {SignInView} from '../auth/SignInView.ts';
import {Elements} from '../dom/Elements.ts';
import type {AdminRuntime} from '../public/AdminRuntime.ts';
import type {Me} from '../public/auth/Me.ts';
import type {User} from '../public/auth/User.ts';
import type {View} from './View.ts';

/**
 * The admin app: asks who's signed in, then shows the sign-in form or the
 * signed-in view, switching as people sign in and out.
 */
export class AdminApp {
    /** @param root Where the app shows its views: the page's body. */
    public constructor(
        private readonly runtime: AdminRuntime,
        private readonly root: HTMLElement,
    ) {
    }

    /** Asks who's signed in, and shows what fits. */
    public async start(): Promise<void> {
        try {
            const me = await this.runtime.api.call<Me>('coconut-cms:auth.me');
            this.showFor(me.user);
        } catch (failure) {
            this.show({
                element: Elements.create('main', {classes: ['sign-in']}, [
                    Elements.create('div', {classes: ['island', 'sign-in__island']}, [
                        Elements.create('h1', {classes: ['sign-in__title'], text: 'The admin can\'t start'}),
                        Elements.create('p', {text: failure instanceof Error ? failure.message : String(failure)}),
                    ]),
                ]),
                shown: () => undefined,
            });
        }
    }

    /** Shows the view for `user`: the sign-in form for nobody. */
    private showFor(user: User | null): void {
        this.runtime.setUser(user);
        this.show(user === null
            ? new SignInView(this.runtime.api, (signedIn) => this.showFor(signedIn))
            : new SignedInView(this.runtime.api, user, () => this.showFor(null)));
    }

    /** Replaces what the root shows with `view`. */
    private show(view: View): void {
        this.root.replaceChildren(view.element);
        view.shown();
    }
}
