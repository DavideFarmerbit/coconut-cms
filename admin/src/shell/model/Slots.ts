import type {Placement} from '../../public/workspace/Placement.ts';
import type {Container} from './Container.ts';

/** Where a slot's panel shows: its container, and its pane there, 0 for the first. */
export interface PaneRef {
    readonly container: Container;
    readonly pane: 0 | 1;
}

/**
 * Which pane each stripe section's panel opens in. A side's two top sections
 * share that side's container, upper then lower; both bottom sections share
 * the bottom container, left then right. So moving an icon moves its panel.
 */
export class Slots {
    /** The pane `placement`'s open tool shows in. */
    public static paneOf(placement: Placement): PaneRef {
        switch (placement.section) {
            case 'topTop':
                return {container: placement.side, pane: 0};
            case 'topBottom':
                return {container: placement.side, pane: 1};
            case 'bottom':
                return {container: 'bottom', pane: placement.side === 'left' ? 0 : 1};
        }
    }

    /** The two placements whose panels share `container`, in pane order. */
    public static placementsIn(container: Container): readonly [Placement, Placement] {
        return container === 'bottom'
            ? [{side: 'left', section: 'bottom'}, {side: 'right', section: 'bottom'}]
            : [{side: container, section: 'topTop'}, {side: container, section: 'topBottom'}];
    }
}
