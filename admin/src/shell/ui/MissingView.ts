import {Elements} from '../../dom/Elements.ts';
import type {PanelView} from '../../public/workspace/PanelView.ts';

/** Shown in place of a view that can't be built: a tab of a type no longer registered, a view that threw. */
export class MissingView implements PanelView {
    public readonly element: HTMLElement;

    public constructor(message: string) {
        this.element = Elements.create('div', {classes: ['view-message']}, [Elements.create('p', {classes: ['muted'], text: message})]);
    }

    public shown(): void {
    }

    public hidden(): void {
    }

    public dispose(): void {
    }
}
