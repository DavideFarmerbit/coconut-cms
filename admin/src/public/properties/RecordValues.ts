/**
 * Values in the form coconut-cms:entities.get gives them, and the
 * properties edit: a scalar or a value object as stored; an Embed or an
 * owned object as an ObjectValue; a Reference as a ReferenceValue; a
 * Collection as a list; null for an empty slot.
 */

/** An Embed, an owned object, or a whole entity: its type, its id (null for an Embed or one not saved yet), its values by field. */
export interface ObjectValue {
    readonly type: string;
    readonly id: string | null;
    readonly values: Record<string, unknown>;
}

/** What a Reference holds: its target's type and id. */
export interface ReferenceValue {
    readonly type: string;
    readonly id: string;
}

/** Whether `value` is an ObjectValue. */
export function isObjectValue(value: unknown): value is ObjectValue {
    return typeof value === 'object' && value !== null && 'type' in value && 'values' in value;
}

/** Whether `value` is a ReferenceValue. */
export function isReferenceValue(value: unknown): value is ReferenceValue {
    return typeof value === 'object' && value !== null && 'type' in value && 'id' in value && !('values' in value);
}
