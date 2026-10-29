import type {PropertyFilter} from '../../public/properties/PropertyFilter.ts';
import type {PropertyInfo} from '../../public/properties/PropertyInfo.ts';
import {PropertyPath} from '../../public/properties/PropertyPath.ts';

/** Accepts the properties under a path, not the one at it: an owned object's fields, its row a plain parent. */
export class UnderFilter implements PropertyFilter {
    public constructor(private readonly path: string) {
    }

    public accepts(property: PropertyInfo): boolean {
        return PropertyPath.isUnder(property.path, this.path);
    }
}
