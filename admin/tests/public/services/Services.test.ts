import {describe, expect, it} from 'vitest';
import {FakeBackend} from '../../FakeBackend.ts';
import {Auth} from '../../../src/public/auth/Auth.ts';
import type {ServiceContext} from '../../../src/public/services/ServiceContext.ts';
import {Services} from '../../../src/public/services/Services.ts';

/** What the tests' services were disposed of in, by class name. */
const disposed: string[] = [];

/** A service using nothing. */
class Ledger {
    public hasUnsaved = false;

    public static create(): Ledger {
        return new Ledger();
    }

    public dispose(): void {
        disposed.push('Ledger');
    }
}

/** A service using the ledger. */
class Orders {
    public constructor(public readonly ledger: Ledger) {
    }

    public static create(context: ServiceContext): Orders {
        return new Orders(context.service(Ledger));
    }

    public dispose(): void {
        disposed.push('Orders');
    }
}

/** Two services each needing the other. */
class Chicken {
    public static create(context: ServiceContext): Chicken {
        context.service(Egg);

        return new Chicken();
    }
}

class Egg {
    public static create(context: ServiceContext): Egg {
        context.service(Chicken);

        return new Egg();
    }
}

/** The services of a user signed in on a fake backend. */
function services(): Services {
    const client = new FakeBackend().client();

    return new Services(client, new Auth(client), {id: 'u1', name: 'ada', roles: []});
}

describe('Services', () => {
    it('creates each service once, on the first ask, built with the others it asks for', () => {
        const all = services();
        const orders = all.get(Orders);

        expect(all.get(Orders)).toBe(orders);
        expect(all.get(Ledger)).toBe(orders.ledger);
    });

    it('refuses services needing each other, naming them', () => {
        expect(() => services().get(Chicken)).toThrow('Chicken → Egg → Chicken');
    });

    it('tells of unsaved work any holds, and disposes of them the last created first', () => {
        disposed.length = 0;
        const all = services();
        all.get(Orders);
        expect(all.hasUnsaved).toBe(false);
        all.get(Ledger).hasUnsaved = true;
        expect(all.hasUnsaved).toBe(true);

        all.dispose();
        expect(disposed, 'The ledger was created first, while creating the orders.').toEqual(['Orders', 'Ledger']);
        expect(all.hasUnsaved).toBe(false);
    });
});
