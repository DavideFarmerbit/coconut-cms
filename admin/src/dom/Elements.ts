/** What Elements.create() sets on an element besides its children. */
export interface ElementOptions {
    /** Its class names. */
    readonly classes?: readonly string[];
    /** Its text, set as text, never parsed as HTML. */
    readonly text?: string;
    /** Its attributes, by name. */
    readonly attributes?: Readonly<Record<string, string>>;
}

/** Builds DOM elements in one call, text always escaped: the admin's only way to make markup. */
export class Elements {
    /** A new `tag` element with `options` applied and `children` appended. */
    public static create<Tag extends keyof HTMLElementTagNameMap>(
        tag: Tag,
        options: ElementOptions = {},
        children: readonly (Node | string)[] = [],
    ): HTMLElementTagNameMap[Tag] {
        const element = document.createElement(tag);
        if (options.classes !== undefined) {
            element.classList.add(...options.classes);
        }

        if (options.text !== undefined) {
            element.textContent = options.text;
        }

        for (const [name, value] of Object.entries(options.attributes ?? {})) {
            element.setAttribute(name, value);
        }

        element.append(...children);

        return element;
    }
}
