// @vitest-environment happy-dom
import {describe, expect, it} from 'vitest';
import type {PanelView} from '../../../src/public/workspace/PanelView.ts';
import {ViewHost} from '../../../src/shell/ui/ViewHost.ts';

/** A view of one button, doing nothing when shown or hidden. */
class ButtonView implements PanelView {
    public readonly element = document.createElement('section');
    public readonly button = document.createElement('button');

    public constructor() {
        this.element.append(this.button);
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
    }
}

describe('ViewHost', () => {
    it('finds the shown view holding an element: where a key was pressed', () => {
        const parking = document.createElement('div');
        const area = document.createElement('main');
        document.body.append(area, parking);
        const host = new ViewHost(parking);
        const first = new ButtonView();
        const second = new ButtonView();
        host.show('tab:1', area, () => first);
        host.show('tab:2', area, () => second);

        expect(host.viewHolding(first.button)).toBe(first);
        expect(host.viewHolding(second.element)).toBe(second);
        expect(host.viewHolding(area)).toBeUndefined();

        host.hide('tab:1');
        expect(host.viewHolding(first.button), 'A hidden view takes no keys.').toBeUndefined();
    });
});
