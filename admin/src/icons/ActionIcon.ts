import {Elements} from '../dom/Elements.ts';
import {Svg} from '../dom/Svg.ts';
import {Tooltips} from '../dom/Tooltips.ts';

/** The admin's action icons, by name. */
export type ActionIconName = 'expand' | 'add' | 'remove' | 'clear' | 'duplicate' | 'moveUp' | 'moveDown' | 'reset' | 'open' | 'browse';

/**
 * The icons of actions, drawn alike: 16 by 16, stroked in currentColor, so
 * they follow the theme. Each comes as an icon button, labelled and with
 * its tooltip.
 */
export class ActionIcon {
    /** Each icon's drawing. */
    private static readonly PATHS: Readonly<Record<ActionIconName, string>> = {
        expand: 'M6 4l4 4-4 4',
        add: 'M8 3.5v9M3.5 8h9',
        remove: 'M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.75 9h5.5l.75-9M7 7v4M9 7v4',
        clear: 'M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.75 9h5.5l.75-9M6.5 7.5l3 3M9.5 7.5l-3 3',
        duplicate: 'M5.5 5.5h7v7h-7zM3.5 10.5v-7h7',
        moveUp: 'M8 12.5v-9M4.5 7 8 3.5 11.5 7',
        moveDown: 'M8 3.5v9M4.5 9 8 12.5 11.5 9',
        reset: 'M3.5 8a4.5 4.5 0 1 0 1.3-3.2M3.5 3v2.5H6',
        open: 'M9 3.5h3.5V7M12.5 3.5 7.5 8.5M11 9.5v3H3.5V5h3',
        browse: 'M7 11.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9ZM10.3 10.3l3 3',
    };

    /** The icon `name`, alone. */
    public static svg(name: ActionIconName): SVGSVGElement {
        return Svg.icon([], [Svg.create('path', {d: ActionIcon.PATHS[name], fill: 'none', stroke: 'currentColor', 'stroke-width': 1.3, 'stroke-linecap': 'round', 'stroke-linejoin': 'round'})]);
    }

    /** An icon button showing `name`, labelled `label`, with its tooltip on `top` unless told otherwise. */
    public static button(name: ActionIconName, label: string, placement: 'top' | 'bottom' = 'top'): HTMLButtonElement {
        return Elements.create('button', {
            classes: ['icon-button'],
            attributes: {type: 'button', 'aria-label': label, ...Tooltips.attributes(label, placement)},
        }, [ActionIcon.svg(name)]);
    }
}
