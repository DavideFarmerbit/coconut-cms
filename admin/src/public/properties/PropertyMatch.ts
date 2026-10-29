import type {ValueKind} from '../types/TypeDescription.ts';

/**
 * What a property editor is registered for, from the most specific to the
 * least:
 * - one field of one type, `{type: 'shop:car', field: 'color'}`, which
 *   holds for the type's subtypes too;
 * - the type of value a property holds, `{valueType: 'shop:color'}`: a
 *   value object's identifier, or an entity type held as an Embed or owned
 *   object, or pointed at;
 * - a scalar type, `{scalar: 'int'}`;
 * - a kind, `{kind: 'embed'}`: the admin's defaults.
 */
export type PropertyMatch =
    | {readonly type: string; readonly field: string}
    | {readonly valueType: string}
    | {readonly scalar: string}
    | {readonly kind: ValueKind};
