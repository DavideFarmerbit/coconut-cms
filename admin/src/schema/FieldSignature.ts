import type {ValueDescription, ValueKind} from '../public/types/TypeDescription.ts';
import type {SignatureData} from './SignatureData.ts';

/**
 * The fields a converter takes or gives, an initializer fills, or a field
 * rule checks, matched the way the server matches them: what a signature
 * leaves out matches any, a union matches any of its signatures, and
 * nullability is never part of it. So the editor offers only what the server
 * would accept.
 */
export class FieldSignature {
    /** How a refusal names a kind: "a Scalar", "an owned object". */
    private static readonly KIND_WORDS: Readonly<Record<ValueKind, string>> = {
        scalar: 'a Scalar',
        value_object: 'a value object',
        embed: 'an Embed',
        reference: 'a Reference',
        owning_reference: 'an owned object',
        collection: 'a Collection',
    };

    private constructor(
        private readonly kind: ValueKind | null,
        private readonly scalarType: string | null,
        private readonly target: string | null,
        private readonly item: FieldSignature | null,
        private readonly oneOf: readonly FieldSignature[],
    ) {
    }

    /** The signature `data` describes, as the server sends it. */
    public static of(data: SignatureData): FieldSignature {
        if ('oneOf' in data) {
            return new FieldSignature(null, null, null, null, data.oneOf.map((each) => FieldSignature.of(each)));
        }

        return new FieldSignature(data.kind, data.scalarType, data.target, data.item === null ? null : FieldSignature.of(data.item), []);
    }

    /** Whether it's a union of others. */
    public isUnion(): boolean {
        return this.oneOf.length > 0;
    }

    /** Whether `value`, what a field holds, is one of the fields this describes. */
    public matches(value: ValueDescription): boolean {
        if (this.isUnion()) {
            return this.oneOf.some((each) => each.matches(value));
        }

        if (this.kind === null) {
            return true;
        }

        if (value.kind !== this.kind) {
            return false;
        }

        switch (this.kind) {
            case 'scalar':
                return this.scalarType === null || value.scalarType === this.scalarType;
            case 'collection':
                return this.item === null || (value.item !== null && this.item.matches(value.item));
            default:
                return this.target === null || value.target === this.target;
        }
    }

    /** How a refusal names it, as the server does: "a Scalar of int, float or decimal". */
    public describe(): string {
        if (this.isUnion()) {
            const scalarTypes = this.oneOf.map((each) => (each.kind === 'scalar' && !each.isUnion() ? each.scalarType : null));
            if (!scalarTypes.includes(null)) {
                return `a Scalar of ${FieldSignature.listed(scalarTypes as string[])}`;
            }

            return FieldSignature.listed(this.oneOf.map((each) => each.describe()));
        }

        if (this.kind === null) {
            return 'any field';
        }

        const kind = FieldSignature.KIND_WORDS[this.kind];
        switch (this.kind) {
            case 'scalar':
                return this.scalarType === null ? kind : `${kind} of ${this.scalarType}`;
            case 'collection':
                return `${kind} of ${this.item?.describe() ?? 'any items'}`;
            default:
                return this.target === null ? kind : `${kind} of ${this.target}`;
        }
    }

    /** `items` as a sentence lists them: "a", "a or b", "a, b or c". */
    private static listed(items: readonly string[]): string {
        const last = items[items.length - 1] ?? '';

        return items.length <= 1 ? last : `${items.slice(0, -1).join(', ')} or ${last}`;
    }
}
