/** A value JSON holds: what a view's saved state and a tab's parameters are made of. */
export type JsonValue = string | number | boolean | null | readonly JsonValue[] | {readonly [key: string]: JsonValue};
