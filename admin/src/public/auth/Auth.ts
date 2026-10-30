import type {ApiClient} from '../api/ApiClient.ts';
import type {Me} from './Me.ts';
import type {SignOutGuard} from './SignOutGuard.ts';
import type {User} from './User.ts';

/** Told that the user changed: someone signed in, or out (null). */
export type UserListener = (user: User | null) => void;

/**
 * Who's signed in, with no DOM: `admin.auth`. It calls the auth operations,
 * and tells its listeners when the user changes. The runtime and the app
 * follow it: the stores start over for each user, the app shows the
 * sign-in form or the shell.
 *
 *     admin.auth.onChange((user) => this.showFor(user));
 *     await admin.auth.check();
 */
export class Auth {
    private current: User | null = null;
    private readonly guards: SignOutGuard[] = [];
    private readonly listeners: UserListener[] = [];

    public constructor(private readonly api: ApiClient) {
    }

    /** Who's signed in; null for nobody, or before check(). */
    public get user(): User | null {
        return this.current;
    }

    /** Tells `listener` of every change of the user. */
    public onChange(listener: UserListener): void {
        this.listeners.push(listener);
    }

    /** Stops telling `listener`. */
    public offChange(listener: UserListener): void {
        const index = this.listeners.indexOf(listener);
        if (index >= 0) {
            this.listeners.splice(index, 1);
        }
    }

    /** Asks `guard` before every sign-out. */
    public guard(guard: SignOutGuard): void {
        this.guards.push(guard);
    }

    /** Stops asking `guard`. */
    public unguard(guard: SignOutGuard): void {
        const index = this.guards.indexOf(guard);
        if (index >= 0) {
            this.guards.splice(index, 1);
        }
    }

    /** Asks the server who's signed in: when the page loads. */
    public async check(): Promise<User | null> {
        const me = await this.api.call<Me>('coconut-cms:auth.me');
        this.become(me.user);

        return me.user;
    }

    /** Signs in; fails with the server's error, a wrong password's included. */
    public async signIn(login: string, password: string, remember: boolean): Promise<User> {
        const me = await this.api.call<Me>('coconut-cms:auth.signIn', {login, password, remember});
        if (me.user === null) {
            throw new Error('The backend signed nobody in.');
        }

        this.become(me.user);

        return me.user;
    }

    /**
     * Signs out, once every guard allows it: the work they hold settled.
     * @return Whether it signed out: false when a guard kept the user signed in.
     */
    public async signOut(): Promise<boolean> {
        for (const guard of [...this.guards]) {
            if (!await guard.allowsSignOut()) {
                return false;
            }
        }

        await this.api.call('coconut-cms:auth.signOut');
        this.become(null);

        return true;
    }

    /** Makes `user` the one signed in, telling the listeners when it's another. */
    private become(user: User | null): void {
        const same = (user?.id ?? null) === (this.current?.id ?? null);
        this.current = user;
        if (same) {
            return;
        }

        for (const listener of [...this.listeners]) {
            listener(user);
        }
    }
}
