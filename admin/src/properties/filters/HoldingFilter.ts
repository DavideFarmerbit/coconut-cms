import type {PropertyFilter} from '../../public/properties/PropertyFilter.ts';
import type {PropertyInfo} from '../../public/properties/PropertyInfo.ts';

/**
 * Accepts the properties holding a type: a value object type
 * (`shop:color`), an entity type held as an Embed, owned object or
 * Reference, or a scalar type (`int`). A Collection's items are accepted one
 * by one, the Collection staying their plain parent.
 */
export class HoldingFilter implements PropertyFilter {
    public constructor(private readonly type: string) {
    }

    public accepts(property: PropertyInfo): boolean {
        return property.value.target === this.type || property.value.scalarType === this.type;
    }
}
