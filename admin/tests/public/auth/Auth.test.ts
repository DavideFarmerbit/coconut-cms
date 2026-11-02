import {describe, expect, it} from 'vitest';
import {FakeBackend, FixedAnswer, storeOf} from '../../FakeBackend.ts';
import {ApiFailure} from '../../../src/public/api/ApiFailure.ts';
import {ErrorCode} from '../../../src/public/api/ErrorCode.ts';
import {Auth} from '../../../src/public/auth/Auth.ts';
import type {SignOutGuard} from '../../../src/public/auth/SignOutGuard.ts';
import type {User} from '../../../src/public/auth/User.ts';

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
        auth.guard(allowing, {name: 'test:allowing'});
        auth.guard(refusing, {name: 'test:refusing'});

        expect(await auth.signOut()).toBe(false);
        expect(auth.user).not.toBeNull();
        expect(backend.signedIn).not.toBeNull();

        auth.unguard(refusing);
        expect(await auth.signOut()).toBe(true);
        expect(allowing.asked).toBe(2);
        expect(refusing.asked).toBe(1);
    });
});

describe('The order of sign-out guards', () => {
    /** A guard writing its name into `asked` when asked, then allowing. */
    function naming(name: string, asked: string[]): SignOutGuard {
        return {allowsSignOut: () => {
            asked.push(name);

            return Promise.resolve(true);
        }};
    }

    it('asks each after the ones it runs after, and the ones naming it before', async () => {
        const auth = new Auth(new FakeBackend().client());
        await auth.signIn('ada', 'lovelace', false);
        const asked: string[] = [];
        auth.guard(naming('entities', asked), {name: 'coconut-cms:entities'});
        auth.guard(naming('orders', asked), {name: 'shop:orders', after: ['coconut-cms:schema']});
        auth.guard(naming('notes', asked), {name: 'shop:notes'});
        auth.guard(naming('schema', asked), {name: 'coconut-cms:schema', before: ['coconut-cms:entities', 'shop:missing']});

        await auth.signOut();
        expect(asked, 'Unrelated ones keep the order they came in; a missing name is left out.').toEqual(['notes', 'schema', 'entities', 'orders']);
    });

    it('refuses a name taken, or places making a loop, keeping what was there', async () => {
        const auth = new Auth(new FakeBackend().client());
        await auth.signIn('ada', 'lovelace', false);
        const asked: string[] = [];
        auth.guard(naming('a', asked), {name: 'test:a', before: ['test:b']});
        auth.guard(naming('b', asked), {name: 'test:b', before: ['test:c']});

        expect(() => auth.guard(naming('again', asked), {name: 'test:a'})).toThrow('registered already');
        expect(() => auth.guard(naming('c', asked), {name: 'test:c', before: ['test:a']})).toThrow('make a loop');
        await auth.signOut();
        expect(asked).toEqual(['a', 'b']);
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

    it('lets sessions go without asking once disposed of', async () => {
        const answer = new FixedAnswer('cancel');
        const {store, holds} = await editing(answer);
        store.dispose();

        expect(await holds[0]!.release()).toBe(true);
        expect(answer.asked).toBe(0);
    });
});
