import {Elements} from '../../dom/Elements.ts';
import {Svg} from '../../dom/Svg.ts';

/** What an access icon stands for. */
export type Access = 'read' | 'write' | 'create' | 'update' | 'delete';

/**
 * An icon for one right, struck through and dimmed when it isn't held: an
 * eye to read, a pencil to write, and one each for creating, updating and
 * deleting entities. Optionally with its name beside it.
 */
export class AccessIcon {
    /** Each right's drawing. */
    private static readonly PATHS: Readonly<Record<Access, string>> = {
        read: 'M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8ZM8 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
        write: 'M10.5 2.5l3 3-8 8H2.5v-3l8-8ZM9 4l3 3',
        create: 'M8 3v10M3 8h10',
        update: 'M13 6.5A5 5 0 0 0 4 4.5M3 9.5a5 5 0 0 0 9 2M4 2v2.5h2.5M12 14v-2.5H9.5',
        delete: 'M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.75 9h5.5l.75-9',
    };

    /** Each right's name. */
    private static readonly NAMES: Readonly<Record<Access, string>> = {read: 'Read', write: 'Write', create: 'Create', update: 'Update', delete: 'Delete'};

    /** The icon of `access`, held or not, with `labelled` adding its name beside it. */
    public static of(access: Access, held: boolean, labelled = false): HTMLElement {
        const layers: SVGElement[] = [Svg.create('path', {d: AccessIcon.PATHS[access], fill: 'none', stroke: 'currentColor', 'stroke-width': 1.3, 'stroke-linecap': 'round', 'stroke-linejoin': 'round'})];
        if (!held) {
            layers.push(Svg.create('path', {d: 'M2.5 13.5 13.5 2.5', stroke: 'currentColor', 'stroke-width': 1.3, 'stroke-linecap': 'round'}));
        }

        const tooltip = `${held ? 'You may' : 'You may not'} ${AccessIcon.NAMES[access].toLowerCase()}${access === 'read' || access === 'write' ? ' it' : ' its entities'}.`;

        return Elements.create('span', {
            classes: ['access', ...(held ? [] : ['is-denied'])],
            attributes: {title: tooltip, role: 'img', 'aria-label': tooltip},
        }, [Svg.icon([], layers), ...(labelled ? [Elements.create('span', {text: AccessIcon.NAMES[access]})] : [])]);
    }
}
