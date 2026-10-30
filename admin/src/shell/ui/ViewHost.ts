import type {PanelView} from '../../public/workspace/PanelView.ts';

/** Element.moveBefore(), where the browser has it: a move keeping focus, scroll and iframes as they are. */
interface MovingParent {
    moveBefore?(node: Node, child: Node | null): void;
}

/** A view the host holds, and whether it's shown. */
interface Hosted {
    readonly view: PanelView;
    shown: boolean;
}

/**
 * Owns every view built so far, by its key: "tool:<id>", "tab:<id>". A view
 * is built the first time it's shown, then kept alive: hidden, its element
 * waits in an off-layout parking element instead of being destroyed. Moving
 * a view keeps its scroll offsets.
 */
export class ViewHost {
    private readonly views = new Map<string, Hosted>();

    /** @param parking Where hidden views wait: an element kept out of the layout. */
    public constructor(private readonly parking: HTMLElement) {
    }

    /** Shows the view under `key` in `into`, building it with `build` the first time. */
    public show(key: string, into: HTMLElement, build: () => PanelView): void {
        let hosted = this.views.get(key);
        if (hosted === undefined) {
            hosted = {view: build(), shown: false};
            this.views.set(key, hosted);
        }

        if (hosted.view.element.parentElement !== into) {
            ViewHost.move(hosted.view.element, into);
        }

        if (!hosted.shown) {
            hosted.shown = true;
            hosted.view.shown();
        }
    }

    /** Hides the view under `key`, keeping it; nothing for one not built. */
    public hide(key: string): void {
        const hosted = this.views.get(key);
        if (hosted === undefined) {
            return;
        }

        if (hosted.view.element.parentElement !== this.parking) {
            ViewHost.move(hosted.view.element, this.parking);
        }

        if (hosted.shown) {
            hosted.shown = false;
            hosted.view.hidden();
        }
    }

    /** Disposes of the view under `key`, for good; nothing for one not built. */
    public dispose(key: string): void {
        const hosted = this.views.get(key);
        if (hosted === undefined) {
            return;
        }

        this.hide(key);
        hosted.view.element.remove();
        hosted.view.dispose();
        this.views.delete(key);
    }

    /** Disposes of every view built: when the shell goes. */
    public disposeAll(): void {
        for (const key of this.keys()) {
            this.dispose(key);
        }
    }

    /** Whether the view under `key` lets its tab close: it may ask about unsaved changes. True for one not built. */
    public async canClose(key: string): Promise<boolean> {
        const view = this.views.get(key)?.view;

        return view?.canClose === undefined ? true : view.canClose();
    }

    /** The keys of every view built. */
    public keys(): readonly string[] {
        return [...this.views.keys()];
    }

    /** Moves `element` into `into`, keeping its scroll offsets where the browser can't move it as is. */
    private static move(element: HTMLElement, into: HTMLElement): void {
        const parent = into as HTMLElement & MovingParent;
        if (typeof parent.moveBefore === 'function' && element.isConnected && into.isConnected) {
            parent.moveBefore(element, null);

            return;
        }

        const scrolled = [element, ...element.querySelectorAll<HTMLElement>('*')]
            .filter((each) => each.scrollTop !== 0 || each.scrollLeft !== 0)
            .map((each) => ({each, top: each.scrollTop, left: each.scrollLeft}));
        into.append(element);
        for (const {each, top, left} of scrolled) {
            each.scrollTop = top;
            each.scrollLeft = left;
        }
    }
}
