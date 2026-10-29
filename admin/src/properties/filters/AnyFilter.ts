import type {PropertyFilter} from '../../public/properties/PropertyFilter.ts';
import type {PropertyInfo} from '../../public/properties/PropertyInfo.ts';

/** Accepts the properties one of its filters accepts at least. */
export class AnyFilter implements PropertyFilter {
    public constructor(private readonly filters: readonly PropertyFilter[]) {
    }

    public accepts(property: PropertyInfo): boolean {
        return this.filters.some((filter) => filter.accepts(property));
    }
}
