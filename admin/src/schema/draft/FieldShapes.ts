import type {FieldShape} from '../SchemaDefinitions.ts';

/** How a field's nullability alone changed: what makeOptional or makeRequired writes. */
export type NullabilityChange = 'optional' | 'required';

/** Compares and rewrites field shapes: what tells a retype from a rename, and keeps type names current. */
export class FieldShapes {
    /** `shape` with every entity type it names given by `rename`: an Embed's, Reference's or owned object's, its items' too. */
    public static retargeted(shape: FieldShape, rename: (identifier: string) => string): FieldShape {
        const item = shape.item === undefined ? {} : {item: FieldShapes.retargeted(shape.item, rename)};
        const names = shape.target !== undefined && FieldShapes.namesType(shape);

        return {...shape, ...item, ...(names ? {target: rename(shape.target!)} : {})};
    }

    /** Whether `a` and `b` hold the same thing. */
    public static same(a: FieldShape, b: FieldShape): boolean {
        return a.kind === b.kind
            && a.nullable === b.nullable
            && a.scalarType === b.scalarType
            && a.enum === b.enum
            && a.target === b.target
            && (a.item === undefined || b.item === undefined ? a.item === b.item : FieldShapes.same(a.item, b.item));
    }

    /** How a field holding `from` changes to hold `to` when only its own or its items' nullability does; null when anything else does, or nothing. */
    public static nullabilityChange(from: FieldShape, to: FieldShape): NullabilityChange | null {
        const [old, now] = from.kind === 'collection' && to.kind === 'collection' && from.item !== undefined && to.item !== undefined
            ? [from.item, to.item]
            : [from, to];
        if (old.nullable === now.nullable || !FieldShapes.same({...from, ...FieldShapes.withItemNullable(from, now.nullable)}, to)) {
            return null;
        }

        return now.nullable ? 'optional' : 'required';
    }

    /** Whether a field holding `shape` names an entity type: a value object's target is no type. */
    private static namesType(shape: FieldShape): boolean {
        return shape.kind === 'embed' || shape.kind === 'reference' || shape.kind === 'owning_reference';
    }

    /** What `shape` becomes with its own nullability, or its items' for a Collection, set to `nullable`. */
    private static withItemNullable(shape: FieldShape, nullable: boolean): Partial<FieldShape> {
        return shape.kind === 'collection' && shape.item !== undefined ? {item: {...shape.item, nullable}} : {nullable};
    }
}
