import type {GuardPlace} from './GuardPlace.ts';
import type {SignOutGuard} from './SignOutGuard.ts';

/** One guard registered, with its place. */
interface Placed {
    readonly guard: SignOutGuard;
    readonly place: GuardPlace;
}

/**
 * The sign-out guards, in the order they run: each after the ones it names
 * in `after`, and the ones naming it in `before`. Guards with nothing
 * between them keep the order they were registered in.
 */
export class GuardOrder {
    private readonly registered: Placed[] = [];

    /** Adds `guard` at `place`. Throws when another guard has its name, or the places make a loop: no order could keep them. */
    public add(guard: SignOutGuard, place: GuardPlace): void {
        if (this.registered.some((each) => each.place.name === place.name)) {
            throw new Error(`A sign-out guard named "${place.name}" is registered already.`);
        }

        GuardOrder.sorted([...this.registered, {guard, place}]);
        this.registered.push({guard, place});
    }

    /** Takes `guard` out. */
    public remove(guard: SignOutGuard): void {
        const index = this.registered.findIndex((each) => each.guard === guard);
        if (index >= 0) {
            this.registered.splice(index, 1);
        }
    }

    /** Every guard, in the order they run. */
    public ordered(): SignOutGuard[] {
        return GuardOrder.sorted(this.registered).map((each) => each.guard);
    }

    /** `guards` in the order they run: each time, the first registered whose guards to run before are all placed. Throws on a loop. */
    private static sorted(guards: readonly Placed[]): Placed[] {
        const byName = new Map(guards.map((each) => [each.place.name, each]));
        // What must run before each guard.
        const earlier = new Map<Placed, Set<Placed>>(guards.map((each) => [each, new Set()]));
        for (const each of guards) {
            for (const name of each.place.after ?? []) {
                const other = byName.get(name);
                if (other !== undefined) {
                    earlier.get(each)!.add(other);
                }
            }
            for (const name of each.place.before ?? []) {
                const other = byName.get(name);
                if (other !== undefined) {
                    earlier.get(other)!.add(each);
                }
            }
        }

        const placed: Placed[] = [];
        const left = [...guards];
        while (left.length > 0) {
            const next = left.findIndex((each) => [...earlier.get(each)!].every((other) => placed.includes(other)));
            if (next < 0) {
                throw new Error(`No order keeps the sign-out guards ${left.map((each) => `"${each.place.name}"`).join(', ')}: their places make a loop.`);
            }
            placed.push(...left.splice(next, 1));
        }

        return placed;
    }
}
