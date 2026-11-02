import type {FieldId} from './DraftIds.ts';

/**
 * Where a default is, as the field objects and item indexes it goes
 * through: `sail:area` is the sail field, then the area field. Held by ids,
 * so a field renamed anywhere along it needs nothing rewritten; written
 * with the names the fields end with.
 */
export class DefaultPath {
    private constructor(public readonly segments: readonly (FieldId | number)[]) {
    }

    /** The path through `segments`, in order. */
    public static of(...segments: (FieldId | number)[]): DefaultPath {
        return new DefaultPath(segments);
    }

    /** Its first field. */
    public get head(): FieldId {
        return this.segments[0] as FieldId;
    }

    /** What tells it from any other path: equal for equal paths. */
    public get key(): string {
        return this.segments.join('/');
    }

    /** Whether it goes through the field `field` anywhere along it. */
    public passes(field: FieldId): boolean {
        return this.segments.includes(field);
    }

    /** Whether it's `prefix`, or lies under it. */
    public startsWith(prefix: DefaultPath): boolean {
        return prefix.segments.every((segment, index) => this.segments[index] === segment);
    }
}
