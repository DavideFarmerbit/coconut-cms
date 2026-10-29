/**
 * A floating element opened from an anchor, as a dropdown: under it, or
 * above it where there's no room below, inside the window. It follows its
 * anchor when the page scrolls. One is open at a time; Escape or a click
 * elsewhere closes it. What it holds is up to its
 * user: the Reference picker, the choice picker.
 */
export class Popup {
    /** The gap between the popup and its anchor, in pixels. */
    private static readonly GAP = 4;

    /** The popup open now, if any. */
    private static current: Popup | undefined;

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
    /** Keeps it at its anchor while anything scrolls or the window resizes; closes it once the anchor scrolled out of sight. */
    private readonly follow = (event: Event): void => {
        if (event.target instanceof Node && this.element.contains(event.target)) {
            return;
        }

        if (this.anchorHidden()) {
            this.close();
        } else {
            this.place();
        }
    };

    private constructor(
        private readonly anchor: HTMLElement,
        private readonly element: HTMLElement,
    ) {
    }

    /** Shows `element` from `anchor`, closing any other popup. */
    public static open(anchor: HTMLElement, element: HTMLElement): Popup {
        Popup.current?.close();
        const popup = new Popup(anchor, element);
        Popup.current = popup;
        document.body.append(element);
        popup.place();
        // Capturing: a scroll inside a panel doesn't bubble to the document.
        document.addEventListener('scroll', popup.follow, true);
        window.addEventListener('resize', popup.follow);

        // Listening from the next tick: the event opening the popup mustn't close it.
        setTimeout(() => {
            document.addEventListener('pointerdown', popup.closeOnOutside);
            document.addEventListener('keydown', popup.closeOnEscape);
        });

        return popup;
    }

    /** Places it again: after its content changed its size. At least as wide as its anchor, as a dropdown. */
    public place(): void {
        const anchor = this.anchor.getBoundingClientRect();
        this.element.style.minWidth = `${anchor.width}px`;
        const {offsetWidth: width, offsetHeight: height} = this.element;
        const below = anchor.bottom + Popup.GAP;
        const top = below + height <= window.innerHeight ? below : anchor.top - height - Popup.GAP;
        this.element.style.top = `${Math.max(0, top)}px`;
        this.element.style.left = `${Math.max(0, Math.min(anchor.left, window.innerWidth - width))}px`;
    }

    /** Whether the anchor's middle is out of sight: scrolled out of its panel, or out of the window. */
    private anchorHidden(): boolean {
        const anchor = this.anchor.getBoundingClientRect();
        const atMiddle = document.elementFromPoint(anchor.left + anchor.width / 2, anchor.top + anchor.height / 2);

        return atMiddle === null || (!this.anchor.contains(atMiddle) && !this.element.contains(atMiddle));
    }

    /** Closes it. */
    public close(): void {
        document.removeEventListener('pointerdown', this.closeOnOutside);
        document.removeEventListener('keydown', this.closeOnEscape);
        document.removeEventListener('scroll', this.follow, true);
        window.removeEventListener('resize', this.follow);
        this.element.remove();
        if (Popup.current === this) {
            Popup.current = undefined;
        }
    }
}
