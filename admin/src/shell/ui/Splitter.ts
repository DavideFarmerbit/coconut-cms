import {Elements} from '../../dom/Elements.ts';
import type {DragRange, SplitterTarget} from './SplitterTarget.ts';

/** A drag in progress: where it began, and what it may reach. */
interface Drag {
    readonly pointer: number;
    readonly range: DragRange;
    readonly overlay: HTMLElement;
    value: number;
}

/**
 * A handle dragging a SplitterTarget, with the pointer captured. During a
 * drag a full-page overlay keeps iframes and editors from taking the
 * pointer, the target is only shown the new value, and the layout changes
 * once, when the pointer is released. Escape cancels.
 */
export class Splitter {
    public readonly element: HTMLElement;
    private drag: Drag | undefined;

    public constructor(private readonly target: SplitterTarget) {
        this.element = Elements.create('div', {classes: ['splitter', `splitter--${target.axis}`], attributes: {role: 'separator'}});
        this.element.addEventListener('pointerdown', (event) => this.start(event));
        this.element.addEventListener('pointermove', (event) => this.moved(event));
        this.element.addEventListener('pointerup', () => this.end(true));
        this.element.addEventListener('pointercancel', () => this.end(false));
        this.element.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.end(false);
            }
        });
    }

    /** Starts a drag at `event`. */
    private start(event: PointerEvent): void {
        if (event.button !== 0) {
            return;
        }

        event.preventDefault();
        this.element.setPointerCapture(event.pointerId);
        this.element.tabIndex = -1;
        this.element.focus();
        const overlay = Elements.create('div', {classes: ['splitter-overlay', `splitter-overlay--${this.target.axis}`]});
        document.body.append(overlay);
        const range = this.target.begin();
        this.drag = {pointer: this.target.axis === 'x' ? event.clientX : event.clientY, range, overlay, value: range.value};
        this.element.classList.add('is-dragging');
    }

    /** Follows the pointer during a drag. */
    private moved(event: PointerEvent): void {
        const drag = this.drag;
        if (drag === undefined) {
            return;
        }

        const pointer = this.target.axis === 'x' ? event.clientX : event.clientY;
        const wanted = drag.range.value + (pointer - drag.pointer) * this.target.direction;
        drag.value = Math.min(drag.range.max, Math.max(Math.min(drag.range.min, drag.range.max), wanted));
        this.target.move(drag.value);
    }

    /** Ends the drag, committing it unless `commit` is false. */
    private end(commit: boolean): void {
        const drag = this.drag;
        if (drag === undefined) {
            return;
        }

        this.drag = undefined;
        drag.overlay.remove();
        this.element.classList.remove('is-dragging');
        if (commit) {
            this.target.commit(drag.value);
        } else {
            this.target.cancel();
        }
    }
}
