/** A screen the admin shows whole: the sign-in form, or the app once signed in. */
export interface View {
    /** Its root element, built once. */
    readonly element: HTMLElement;

    /** Called once it's in the document: to focus a field, say. */
    shown(): void;

    /** Called once another screen replaces it: to let go of what it holds. */
    dispose(): void;
}
