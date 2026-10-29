import {describe, expect, it} from 'vitest';
import {toolId} from '../../public/workspace/Ids.ts';
import {Layout} from './Layout.ts';
import {SizeResolver} from './SizeResolver.ts';

const left = toolId('test:left');
const right = toolId('test:right');
const bottom = toolId('test:bottom');

/** A layout with the left and right containers open, preferring 300 and 400. */
function bothSides(): Layout {
    return Layout.initial()
        .reconciled([
            {id: left, placement: {side: 'left', section: 'topTop'}},
            {id: right, placement: {side: 'right', section: 'topTop'}},
            {id: bottom, placement: {side: 'left', section: 'bottom'}},
        ], new Set())
        .toggleTool(left).toggleTool(right)
        .resized('left', 300).resized('right', 400);
}

describe('SizeResolver', () => {
    const resolver = new SizeResolver({canvasWidth: 300, canvasHeight: 200, container: 100});

    it('gives a closed container nothing', () => {
        expect(resolver.resolve(Layout.initial(), {width: 1000, height: 800})).toEqual({left: 0, right: 0, bottom: 0});
    });

    it('gives the preferred sizes while the canvas keeps its minimum', () => {
        expect(resolver.resolve(bothSides(), {width: 1000, height: 800})).toMatchObject({left: 300, right: 400});
    });

    it('shrinks the containers in proportion to their room above their minimums once the canvas is at its minimum', () => {
        // 800 wide leaves 500 for 700 preferred: 200 short. Rooms are 200 and 300.
        const sizes = resolver.resolve(bothSides(), {width: 800, height: 800});

        expect(sizes.left).toBeCloseTo(220);
        expect(sizes.right).toBeCloseTo(280);
    });

    it('never shrinks a container below its minimum: the canvas does', () => {
        expect(resolver.resolve(bothSides(), {width: 400, height: 800})).toMatchObject({left: 100, right: 100});
    });

    it('lets a squeezed container only shrink', () => {
        const layout = bothSides();
        const area = {width: 800, height: 800};

        expect(resolver.maximumOf('left', layout, area)).toBeCloseTo(resolver.resolve(layout, area).left);
        expect(resolver.maximumOf('left', layout, {width: 2000, height: 800})).toBe(2000 - 300 - 400);
    });

    it('sizes the bottom container against the height', () => {
        const layout = bothSides().toggleTool(bottom).resized('bottom', 500);

        expect(resolver.resolve(layout, {width: 1000, height: 600}).bottom).toBe(400);
    });
});
