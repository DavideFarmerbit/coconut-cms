import {Elements} from './Elements.ts';
import type {TooltipPlacement} from './TooltipPlacement.ts';

/**
 * The admin's tooltips, in place of the browser's slow, unstyled ones: an
 * element with a `data-tooltip` attribute shows its text in a floating box
 * shortly after the pointer rests on it, or it gets keyboard focus. The
 * element chooses the side, with `data-tooltip-placement`: the box is
 * centered along that side, flipped to the other one where there's no room,
 * and shifted to stay inside the window. Once one shows, moving to another
 * element shows its own at once, so a row of icons reads quickly. Installed
 * once, listening on the whole document.
 */
export class Tooltips {
    /** The attribute holding an element's tooltip. */
    public static readonly ATTRIBUTE = 'data-tooltip';

    /** The attribute holding the side it shows on: "top" when there's none. */
    public static readonly PLACEMENT_ATTRIBUTE = 'data-tooltip-placement';

    /** How long the pointer rests before a tooltip shows, in milliseconds. */
    private static readonly DELAY = 150;

    /** How long after one hides the next still shows at once, in milliseconds. */
    private static readonly WARM = 400;

    /** The gap between an element and its tooltip, in pixels. */
    private static readonly GAP = 6;

    private readonly box: HTMLElement;
    private target: HTMLElement | undefined;
    private showTimer: ReturnType<typeof setTimeout> | undefined;
    private hiddenAt = 0;

    private constructor(document: Document) {
        this.box = Elements.create('div', {classes: ['tooltip'], attributes: {role: 'tooltip'}});
        this.box.hidden = true;

        document.addEventListener('pointerover', (event) => this.enter(event.target));
        document.addEventListener('pointerout', (event) => this.leave(event.target, event.relatedTarget));
        document.addEventListener('focusin', (event) => this.enter(event.target));
        document.addEventListener('focusout', () => this.hide());
        document.addEventListener('pointerdown', () => this.hide());
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.hide();
            }
        });
    }

    /** Starts the admin's tooltips on `document`. */
    public static install(document: Document): void {
        new Tooltips(document);
    }

    /** The attributes giving an element the tooltip `text`, shown on its `placement` side. */
    public static attributes(text: string, placement: TooltipPlacement = 'top'): Record<string, string> {
        return {[Tooltips.ATTRIBUTE]: text, [Tooltips.PLACEMENT_ATTRIBUTE]: placement};
    }

    /** The pointer or focus reached `node`: shows its tooltip, at once if one showed a moment ago. */
    private enter(node: EventTarget | null): void {
        const target = Tooltips.owner(node);
        if (target === this.target) {
            return;
        }

        this.hide();
        if (target === undefined) {
            return;
        }

        this.target = target;
        const warm = performance.now() - this.hiddenAt < Tooltips.WARM;
        this.showTimer = setTimeout(() => this.show(target), warm ? 0 : Tooltips.DELAY);
    }

    /** The pointer left `node` for `next`: hides the tooltip unless it stays within the same element. */
    private leave(node: EventTarget | null, next: EventTarget | null): void {
        const target = Tooltips.owner(node);
        if (target !== undefined && target === this.target && Tooltips.owner(next) !== target) {
            this.hide();
        }
    }

    /** Shows `target`'s tooltip on the side it asks for, or the opposite one where there's no room. */
    private show(target: HTMLElement): void {
        const text = target.getAttribute(Tooltips.ATTRIBUTE);
        if (text === null || text === '' || !target.isConnected) {
            return;
        }

        // Attached on show: the app replaces the body's children as it switches views.
        if (!this.box.isConnected) {
            target.ownerDocument.body.append(this.box);
        }

        this.box.textContent = text;
        this.box.hidden = false;
        const anchor = target.getBoundingClientRect();
        const box = this.box.getBoundingClientRect();
        const above = anchor.top - box.height - Tooltips.GAP >= 0;
        const below = anchor.bottom + box.height + Tooltips.GAP <= window.innerHeight;
        const room: Record<TooltipPlacement, boolean> = {
            'top': above,
            'top-start': above,
            'bottom': below,
            'bottom-start': below,
            'left': anchor.left - box.width - Tooltips.GAP >= 0,
            'right': anchor.right + box.width + Tooltips.GAP <= window.innerWidth,
        };
        const opposite: Record<TooltipPlacement, TooltipPlacement> = {
            'top': 'bottom',
            'bottom': 'top',
            'top-start': 'bottom-start',
            'bottom-start': 'top-start',
            'left': 'right',
            'right': 'left',
        };
        const asked = Tooltips.placementOf(target);
        const side = room[asked] || !room[opposite[asked]] ? asked : opposite[asked];

        // Each placement's top and left corner.
        const centeredLeft = anchor.left + anchor.width / 2 - box.width / 2;
        const centeredTop = anchor.top + anchor.height / 2 - box.height / 2;
        const corners: Record<TooltipPlacement, readonly [number, number]> = {
            'top': [anchor.top - box.height - Tooltips.GAP, centeredLeft],
            'bottom': [anchor.bottom + Tooltips.GAP, centeredLeft],
            'top-start': [anchor.top - box.height - Tooltips.GAP, anchor.left],
            'bottom-start': [anchor.bottom + Tooltips.GAP, anchor.left],
            'left': [centeredTop, anchor.left - box.width - Tooltips.GAP],
            'right': [centeredTop, anchor.right + Tooltips.GAP],
        };
        const [top, left] = corners[side];
        this.box.style.top = `${Math.min(Math.max(0, top), window.innerHeight - box.height)}px`;
        this.box.style.left = `${Math.min(Math.max(0, left), window.innerWidth - box.width)}px`;
    }

    /** Hides the tooltip, and cancels one about to show. */
    private hide(): void {
        clearTimeout(this.showTimer);
        if (!this.box.hidden) {
            this.hiddenAt = performance.now();
        }

        this.box.hidden = true;
        this.target = undefined;
    }

    /** The placement `target` asks its tooltip on: "top" unless it names another. */
    private static placementOf(target: HTMLElement): TooltipPlacement {
        const asked = target.getAttribute(Tooltips.PLACEMENT_ATTRIBUTE);
        const placements: readonly TooltipPlacement[] = ['top', 'bottom', 'left', 'right', 'top-start', 'bottom-start'];

        return placements.find((placement) => placement === asked) ?? 'top';
    }

    /** The element whose tooltip `node` shows: itself or its nearest ancestor with one. */
    private static owner(node: EventTarget | null): HTMLElement | undefined {
        return node instanceof Element ? node.closest<HTMLElement>(`[${Tooltips.ATTRIBUTE}]`) ?? undefined : undefined;
    }
}
