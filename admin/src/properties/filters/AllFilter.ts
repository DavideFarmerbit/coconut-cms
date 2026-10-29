import type {PropertyFilter} from '../../public/properties/PropertyFilter.ts';
import type {PropertyInfo} from '../../public/properties/PropertyInfo.ts';

/** Accepts the properties every one of its filters accepts. */
export class AllFilter implements PropertyFilter {
    public constructor(private readonly filters: readonly PropertyFilter[]) {
    }

    public accepts(property: PropertyInfo): boolean {
        return this.filters.every((filter) => filter.accepts(property));
    }
}
