import type {PropertyFilter} from '../../public/properties/PropertyFilter.ts';
import type {PropertyInfo} from '../../public/properties/PropertyInfo.ts';

/** Accepts the properties at the paths given: "name", "engine". */
export class PathsFilter implements PropertyFilter {
    private readonly paths: ReadonlySet<string>;

    public constructor(paths: readonly string[]) {
        this.paths = new Set(paths);
    }

    public accepts(property: PropertyInfo): boolean {
        return this.paths.has(property.path);
    }
}
