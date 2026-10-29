import type {PropertyFilter} from '../../public/properties/PropertyFilter.ts';
import type {PropertyInfo} from '../../public/properties/PropertyInfo.ts';

/** Accepts the properties the user may change. */
export class WritableFilter implements PropertyFilter {
    public accepts(property: PropertyInfo): boolean {
        return property.writable;
    }
}
