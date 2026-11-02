/** A converter or initializer chosen: its identifier, as schema.conversions lists it, and its parameters. */
export interface Conversion {
    readonly kind: string;
    readonly parameters: Readonly<Record<string, unknown>>;
}
