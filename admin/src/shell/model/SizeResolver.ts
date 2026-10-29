import type {Container} from './Container.ts';
import type {Layout} from './Layout.ts';

/** The workspace's measured size, in pixels. */
export interface Area {
    readonly width: number;
    readonly height: number;
}

/** The smallest sizes the resolver keeps, in pixels. */
export interface Minimums {
    /** The canvas's width and height, before any container shrinks. */
    readonly canvasWidth: number;
    readonly canvasHeight: number;
    /** An open container's size. */
    readonly container: number;
}

/** One length to share along an axis: what it prefers, and the least it takes. */
interface Claim {
    readonly preferred: number;
    readonly minimum: number;
}

/**
 * Turns a layout's preferred container sizes into the sizes shown, for the
 * workspace's size now. The canvas gives way first; once it's at its
 * minimum, the containers shrink, each in proportion to its room above its
 * own minimum. Growing back, the containers return to their preferred sizes
 * before the canvas grows. Pure: what it gives is never stored.
 */
export class SizeResolver {
    public static readonly MINIMUMS: Minimums = {canvasWidth: 320, canvasHeight: 200, container: 160};

    public constructor(private readonly minimums: Minimums = SizeResolver.MINIMUMS) {
    }

    /** Each container's size shown in `area`: 0 for one with no open panel. */
    public resolve(layout: Layout, area: Area): Record<Container, number> {
        const [left, right] = this.axis(area.width, this.minimums.canvasWidth, [this.claim(layout, 'left'), this.claim(layout, 'right')]);
        const [bottom] = this.axis(area.height, this.minimums.canvasHeight, [this.claim(layout, 'bottom')]);

        return {left: left ?? 0, right: right ?? 0, bottom: bottom ?? 0};
    }

    /**
     * The most `container` may be dragged to, in `area`: what the canvas's
     * minimum and the other side's container leave. A container squeezed
     * below its preferred size already fills that room, so it can only
     * shrink.
     */
    public maximumOf(container: Container, layout: Layout, area: Area): number {
        const shown = this.resolve(layout, area);
        const room = container === 'bottom'
            ? area.height - this.minimums.canvasHeight
            : area.width - this.minimums.canvasWidth - shown[container === 'left' ? 'right' : 'left'];

        return Math.max(0, room);
    }

    /** The least an open container is dragged to. */
    public get minimum(): number {
        return this.minimums.container;
    }

    /** What `container` claims: nothing when it's closed. */
    private claim(layout: Layout, container: Container): Claim {
        return layout.isContainerOpen(container)
            ? {preferred: layout.sizeOf(container), minimum: Math.min(this.minimums.container, layout.sizeOf(container))}
            : {preferred: 0, minimum: 0};
    }

    /** `claims` shared along an axis `available` long, the canvas keeping `canvasMinimum` while it can. */
    private axis(available: number, canvasMinimum: number, claims: readonly Claim[]): number[] {
        const preferred = claims.reduce((total, claim) => total + claim.preferred, 0);
        const deficit = preferred - (available - canvasMinimum);
        if (deficit <= 0) {
            return claims.map((claim) => claim.preferred);
        }

        const room = claims.map((claim) => claim.preferred - claim.minimum);
        const totalRoom = room.reduce((total, each) => total + each, 0) || 1;
        const taken = Math.min(deficit, totalRoom);

        return claims.map((claim, index) => claim.preferred - taken * ((room[index] ?? 0) / totalRoom));
    }
}
