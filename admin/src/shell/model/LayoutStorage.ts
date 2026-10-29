import type {Layout} from './Layout.ts';

/** Where the layout is kept between visits: the browser first, per user through an operation later. */
export interface LayoutStorage {
    /** The layout saved; null when there's none, or it can't be read. */
    load(): Layout | null;

    /** Saves `layout`, replacing what was saved. */
    save(layout: Layout): void;
}
