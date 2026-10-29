import type {Layout} from './Layout.ts';
import type {LayoutListener} from './LayoutListener.ts';
import type {LayoutStorage} from './LayoutStorage.ts';

/**
 * Holds the current layout, the only place it changes: update() replaces it,
 * tells every listener, and saves it a moment later, once changes settle.
 */
export class LayoutStore {
    /** How long after the last change the layout is saved, in milliseconds. */
    public static readonly SAVE_DELAY = 300;

    private readonly listeners: LayoutListener[] = [];
    private saveTimer: ReturnType<typeof setTimeout> | undefined;

    public constructor(
        private layout: Layout,
        private readonly storage: LayoutStorage,
    ) {
    }

    /** The layout now. */
    public get current(): Layout {
        return this.layout;
    }

    /** Tells `listener` of every change from now on. */
    public subscribe(listener: LayoutListener): void {
        this.listeners.push(listener);
    }

    /** Makes `next` the layout; nothing when it's the same one. */
    public update(next: Layout): void {
        if (next === this.layout) {
            return;
        }

        const previous = this.layout;
        this.layout = next;
        for (const listener of this.listeners) {
            listener.layoutChanged(previous, next);
        }

        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => this.storage.save(this.layout), LayoutStore.SAVE_DELAY);
    }
}
