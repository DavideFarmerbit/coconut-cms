import type {PropertyFilter} from '../../public/properties/PropertyFilter.ts';
import type {PropertyInfo} from '../../public/properties/PropertyInfo.ts';
import type {ValueKind} from '../../public/types/TypeDescription.ts';

/** Accepts the properties of the kinds given: Collections, References… */
export class KindFilter implements PropertyFilter {
    private readonly kinds: ReadonlySet<ValueKind>;

    public constructor(kinds: readonly ValueKind[]) {
        this.kinds = new Set(kinds);
    }

    public accepts(property: PropertyInfo): boolean {
        return this.kinds.has(property.value.kind);
    }
}
