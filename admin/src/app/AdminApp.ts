import {SignInView} from '../auth/SignInView.ts';
import {Elements} from '../dom/Elements.ts';
import type {AdminRuntime} from '../public/AdminRuntime.ts';
import type {User} from '../public/auth/User.ts';
import {Shell} from '../shell/Shell.ts';
import type {View} from './View.ts';

/**
 * The admin app: the sign-in form for nobody, the shell for a user. It
 * follows `admin.auth`: a sign-in shows the shell, a sign-out the form, the
 * screen replaced disposed of.
 */
export class AdminApp {
    /** The screen shown now. */
    private current: View | undefined;

    public constructor(
        private readonly runtime: AdminRuntime,
        private readonly root: HTMLElement,
    ) {
    }

    /** Asks who's signed in, shows what fits, then follows every change. */
    public async start(): Promise<void> {
        const auth = this.runtime.auth;
        try {
            this.showFor(await auth.check());
            auth.onChange((user) => this.showFor(user));
        } catch (failure) {
            this.show({
                element: Elements.create('main', {classes: ['sign-in']}, [
                    Elements.create('div', {classes: ['island', 'sign-in__island']}, [
                        Elements.create('h1', {classes: ['sign-in__title'], text: 'The admin can\'t start'}),
                        Elements.create('p', {text: failure instanceof Error ? failure.message : String(failure)}),
                    ]),
                ]),
                shown: () => undefined,
                dispose: () => undefined,
            });
        }
    }

    /** Shows the screen for `user`: the sign-in form for nobody. */
    private showFor(user: User | null): void {
        const auth = this.runtime.auth;
        this.show(user === null
            ? new SignInView(auth)
            : new Shell(this.runtime, user, () => void auth.signOut()));
    }

    /** Replaces what the root shows with `view`, disposing of the screen it replaces. */
    private show(view: View): void {
        this.current?.dispose();
        this.current = view;
        this.root.replaceChildren(view.element);
        view.shown();
    }
}
