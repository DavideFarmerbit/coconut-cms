import {Elements} from '../dom/Elements.ts';
import {Svg} from '../dom/Svg.ts';
import {Tooltips} from '../dom/Tooltips.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';
import {type IconGlyph, IconParts} from './IconParts.ts';
import {ValueWords} from './ValueWords.ts';

/**
 * A field's type icon, stacked from IconParts' layers: the frame, the glyph
 * in its center, the "may be empty" badge in its corner. Everything is drawn
 * in currentColor, which the icon sets to a --cc-type-* token, so a theme
 * recolors every icon. Its tooltip says it all in words.
 */
export class TypeIcon {
    /** The left edges of a Collection grid's cells, in each direction. */
    private static readonly GRID = [0.5, 6, 11.5] as const;

    /** A Collection grid cell's size. */
    private static readonly CELL = 4;

    /** The icon of `value`, with its tooltip. */
    public static of(value: ValueDescription): HTMLElement {
        const parts = IconParts.of(value);
        const layers: SVGElement[] = parts.frame === 'single'
            ? [Svg.create('circle', {cx: 8, cy: 8, r: 6.25, fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5}), TypeIcon.glyph(parts.glyph)]
            : TypeIcon.grid(parts.glyph);
        if (parts.nullable) {
            layers.push(TypeIcon.badge());
        }

        return Elements.create('span', {
            classes: ['type-icon'],
            attributes: {style: `color: var(--cc-type-${parts.color})`, ...Tooltips.attributes(ValueWords.describe(value), 'top'), role: 'img', 'aria-label': ValueWords.describe(value)},
        }, [Svg.icon([], layers)]);
    }

    /** `glyph`, centered in the 16 by 16 box. */
    private static glyph(glyph: IconGlyph): SVGElement {
        switch (glyph) {
            case 'value':
                return Svg.create('circle', {cx: 8, cy: 8, r: 2.75, fill: 'currentColor'});
            case 'embed':
                return Svg.create('rect', {x: 5, y: 5, width: 6, height: 6, rx: 1, fill: 'currentColor'});
            case 'owned':
                return Svg.create('path', {d: 'M8 4.25 11.75 8 8 11.75 4.25 8Z', fill: 'currentColor'});
            case 'reference':
                return Svg.create('path', {d: 'M4.5 8h6M8.5 5.25 11.25 8 8.5 10.75', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.6, 'stroke-linecap': 'round', 'stroke-linejoin': 'round'});
        }
    }

    /**
     * A Collection's 3 by 3 grid. A value's grid is full; any other glyph
     * takes the center cell's place, shrunk to fit it. An Embed's filled
     * square would read as one more cell there, so it's outlined instead.
     */
    private static grid(glyph: IconGlyph): SVGElement[] {
        const cells: SVGElement[] = [];
        for (const y of TypeIcon.GRID) {
            for (const x of TypeIcon.GRID) {
                const center = x === TypeIcon.GRID[1] && y === TypeIcon.GRID[1];
                if (!center || glyph === 'value') {
                    cells.push(Svg.create('rect', {x, y, width: TypeIcon.CELL, height: TypeIcon.CELL, rx: 0.75, fill: 'currentColor'}));
                }
            }
        }

        if (glyph === 'embed') {
            cells.push(Svg.create('rect', {x: 5.9, y: 5.9, width: 4.2, height: 4.2, rx: 0.5, fill: 'none', stroke: 'currentColor', 'stroke-width': 1.1}));
        } else if (glyph !== 'value') {
            cells.push(Svg.create('g', {transform: 'translate(8 8) scale(0.62) translate(-8 -8)'}, [TypeIcon.glyph(glyph)]));
        }

        return cells;
    }

    /** The "may be empty" sign: a slashed circle in the bottom-right corner, on the island's color. */
    private static badge(): SVGElement {
        return Svg.create('g', {}, [
            Svg.create('circle', {cx: 12.5, cy: 12.5, r: 3.4, fill: 'var(--cc-color-island)'}),
            Svg.create('circle', {cx: 12.5, cy: 12.5, r: 2.4, fill: 'none', stroke: 'currentColor', 'stroke-width': 1.1}),
            Svg.create('path', {d: 'M10.8 14.2 14.2 10.8', stroke: 'currentColor', 'stroke-width': 1.1, 'stroke-linecap': 'round'}),
        ]);
    }
}
