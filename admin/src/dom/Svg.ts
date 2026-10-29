/** Builds SVG elements in one call, the way Elements builds HTML ones. */
export class Svg {
    private static readonly NAMESPACE = 'http://www.w3.org/2000/svg';

    /** A new `tag` SVG element with `attributes` set and `children` appended. */
    public static create<Tag extends keyof SVGElementTagNameMap>(
        tag: Tag,
        attributes: Readonly<Record<string, string | number>> = {},
        children: readonly SVGElement[] = [],
    ): SVGElementTagNameMap[Tag] {
        const element = document.createElementNS(Svg.NAMESPACE, tag);
        for (const [name, value] of Object.entries(attributes)) {
            element.setAttribute(name, String(value));
        }

        element.append(...children);

        return element;
    }

    /** A 16 by 16 icon holding `children`, drawn in currentColor, hidden from screen readers. */
    public static icon(classes: readonly string[], children: readonly SVGElement[]): SVGSVGElement {
        const icon = Svg.create('svg', {viewBox: '0 0 16 16', width: 16, height: 16, 'aria-hidden': 'true', focusable: 'false'}, children);
        icon.classList.add(...classes);

        return icon;
    }
}
