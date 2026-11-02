import type {ValueKind} from '../public/types/TypeDescription.ts';

/**
 * A field signature as the server sends it: the keys types.get describes a
 * field with, each null for any, or a union of signatures.
 */
export type SignatureData = ShapeData | UnionData;

/** One shape: a kind, narrowed or not. Null keys match any. */
export interface ShapeData {
    readonly kind: ValueKind | null;
    readonly scalarType: string | null;
    readonly target: string | null;
    readonly item: SignatureData | null;
}

/** Any of several signatures. */
export interface UnionData {
    readonly oneOf: readonly SignatureData[];
}
