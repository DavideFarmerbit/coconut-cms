import {Elements} from './Elements.ts';
import type {MenuItem} from './MenuItem.ts';

/**
 * A menu floating at a point: a right-click's choices, or a dropdown's. One
 * is open at a time; choosing an item, pressing Escape or clicking anywhere
 * else closes it. Kept inside the window.
 */
export class ContextMenu {
    /** The menu open now, if any. */
    private static current: ContextMenu | undefined;

    private readonly element: HTMLElement;
    private readonly closeOnOutside = (event: Event): void => {
        if (!(event.target instanceof Node) || !this.element.contains(event.target)) {
            this.close();
        }
    };
    private readonly closeOnEscape = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            this.close();
        }
    };

    private constructor(items: readonly MenuItem[]) {
        this.element = Elements.create('ul', {classes: ['context-menu'], attributes: {role: 'menu'}}, items.map((item) => {
            const button = Elements.create('button', {classes: ['context-menu__item'], text: item.label, attributes: {type: 'button', role: 'menuitem'}});
            button.addEventListener('click', () => {
                this.close();
                item.run();
            });

            return Elements.create('li', {}, [button]);
        }));
    }

    /** Opens a menu of `items` with its top-left corner at `x`, `y`, in window coordinates, closing any other. */
    public static show(x: number, y: number, items: readonly MenuItem[]): void {
        ContextMenu.current?.close();
        const menu = new ContextMenu(items);
        ContextMenu.current = menu;
        document.body.append(menu.element);

        const {width, height} = menu.element.getBoundingClientRect();
        menu.element.style.left = `${Math.max(0, Math.min(x, window.innerWidth - width))}px`;
        menu.element.style.top = `${Math.max(0, Math.min(y, window.innerHeight - height))}px`;
        menu.element.querySelector('button')?.focus();

        // Listening from the next tick: the event opening the menu mustn't close it.
        setTimeout(() => {
            document.addEventListener('pointerdown', menu.closeOnOutside);
            document.addEventListener('keydown', menu.closeOnEscape);
        });
    }

    /** Opens a menu of `items` under `anchor`: a dropdown. */
    public static below(anchor: HTMLElement, items: readonly MenuItem[]): void {
        const rect = anchor.getBoundingClientRect();
        ContextMenu.show(rect.left, rect.bottom + 4, items);
    }

    /** Closes it. */
    private close(): void {
        document.removeEventListener('pointerdown', this.closeOnOutside);
        document.removeEventListener('keydown', this.closeOnEscape);
        this.element.remove();
        if (ContextMenu.current === this) {
            ContextMenu.current = undefined;
        }
    }
}
