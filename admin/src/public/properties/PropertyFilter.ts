import type {PropertyInfo} from './PropertyInfo.ts';

/**
 * Decides which properties a PropertyGrid shows. A property shows when the
 * filter accepts it or one of its ancestors: accepting a property accepts
 * what's in it. The ancestors of a property shown stay as plain parent
 * rows, so a match deep in an object keeps its place. The built-in filters
 * come from PropertyFilters; a plugin writes its own as a class.
 */
export interface PropertyFilter {
    /** Whether `property` shows, with what's in it. */
    accepts(property: PropertyInfo): boolean;
}
