import {describe, expect, it} from 'vitest';
import {FakeBackend, FixedAnswer, storeOf} from '../../testing/FakeBackend.ts';
import {ApiFailure} from '../api/ApiFailure.ts';
import {ErrorCode} from '../api/ErrorCode.ts';
import {Auth} from './Auth.ts';
import type {SignOutGuard} from './SignOutGuard.ts';
import type {User} from './User.ts';

/** A guard answering `allows`, counting how often it was asked. */
class FixedGuard implements SignOutGuard {
    public asked = 0;

    public constructor(private readonly allows: boolean) {
    }

    public allowsSignOut(): Promise<boolean> {
        this.asked++;

        return Promise.resolve(this.allows);
    }
}

describe('Auth', () => {
    it('checks who\'s signed in, signs in and out, telling of each change', async () => {
        const backend = new FakeBackend();
        const auth = new Auth(backend.client());
        const told: (User | null)[] = [];
        auth.onChange((user) => told.push(user));

        expect(await auth.check()).toBeNull();
        expect(told).toEqual([]);

        await auth.signIn('ada', 'lovelace', false);
        expect(auth.user?.name).toBe('ada');

        expect(await auth.check()).not.toBeNull();
        expect(await auth.signOut()).toBe(true);
        expect(auth.user).toBeNull();
        expect(told.map((user) => user?.name ?? null)).toEqual(['ada', null]);
    });

    it('fails a wrong sign-in with the server\'s error, nobody signed in', async () => {
        const auth = new Auth(new FakeBackend().client());

        await expect(auth.signIn('ada', 'wrong', false)).rejects.toSatisfy((failure: unknown) => failure instanceof ApiFailure && failure.code === ErrorCode.SignInFailed);
        expect(auth.user).toBeNull();
    });

    it('signs out only once every guard allows it', async () => {
        const backend = new FakeBackend();
        const auth = new Auth(backend.client());
        await auth.signIn('ada', 'lovelace', false);
        const allowing = new FixedGuard(true);
        const refusing = new FixedGuard(false);
        auth.guard(allowing);
        auth.guard(refusing);

        expect(await auth.signOut()).toBe(false);
        expect(auth.user).not.toBeNull();
        expect(backend.signedIn).not.toBeNull();

        auth.unguard(refusing);
        expect(await auth.signOut()).toBe(true);
        expect(allowing.asked).toBe(2);
        expect(refusing.asked).toBe(1);
    });
});

describe('Signing out with unsaved entities', () => {
    /** A store with Alpha and Beta edited, and Gamma open but clean. */
    async function editing(answer: FixedAnswer): Promise<{backend: FakeBackend; store: ReturnType<typeof storeOf>; holds: ReturnType<ReturnType<typeof storeOf>['edit']>[]}> {
        const backend = new FakeBackend();
        const store = storeOf(backend, answer);
        const holds = ['Alpha', 'Beta', 'Gamma'].map((name) => store.edit(backend.addCar(name)));
        await Promise.all(holds.map((hold) => hold.session.ready));
        holds[0]!.session.set('power', 1);
        holds[1]!.session.set('power', 2);

        return {backend, store, holds};
    }

    it('asks once, about every unsaved entity by label', async () => {
        const answer = new FixedAnswer('cancel');
        const {store} = await editing(answer);

        expect(await store.allowsSignOut()).toBe(false);
        expect(answer.askedAll).toEqual([['Alpha', 'Beta']]);
        expect(answer.asked).toBe(0);
    });

    it('saves all, or discards all', async () => {
        const saving = await editing(new FixedAnswer('save'));
        expect(await saving.store.allowsSignOut()).toBe(true);
        expect([...saving.backend.entities.values()].map((entity) => entity.values['power'])).toEqual([1, 2, 100]);

        const discarding = await editing(new FixedAnswer('discard'));
        expect(await discarding.store.allowsSignOut()).toBe(true);
        expect(discarding.store.hasUnsaved).toBe(false);
        expect([...discarding.backend.entities.values()].map((entity) => entity.values['power'])).toEqual([100, 100, 100]);
    });

    it('keeps the user signed in when a save fails', async () => {
        const {backend, store, holds} = await editing(new FixedAnswer('save'));
        backend.failNextUpdate = {code: 'validation_failed', errors: {power: ['Too low.']}};

        expect(await store.allowsSignOut()).toBe(false);
        expect(holds.some((hold) => hold.session.errorAt('power') === 'Too low.')).toBe(true);
    });

    it('lets sessions go without asking once closed', async () => {
        const answer = new FixedAnswer('cancel');
        const {store, holds} = await editing(answer);
        store.close();

        expect(await holds[0]!.release()).toBe(true);
        expect(answer.asked).toBe(0);
    });
});
