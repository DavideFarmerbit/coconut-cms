import {AllFilter} from '../../properties/filters/AllFilter.ts';
import {AnyFilter} from '../../properties/filters/AnyFilter.ts';
import {HoldingFilter} from '../../properties/filters/HoldingFilter.ts';
import {KindFilter} from '../../properties/filters/KindFilter.ts';
import {PathsFilter} from '../../properties/filters/PathsFilter.ts';
import {UnderFilter} from '../../properties/filters/UnderFilter.ts';
import {WritableFilter} from '../../properties/filters/WritableFilter.ts';
import type {ValueKind} from '../types/TypeDescription.ts';
import type {PropertyFilter} from './PropertyFilter.ts';

/**
 * The built-in property filters, to narrow a PropertyGrid and combine.
 *
 *     new PropertyGrid(context, session, {filter: PropertyFilters.all(PropertyFilters.writable(), PropertyFilters.under('engine'))});
 */
export class PropertyFilters {
    /** The properties the user may change. */
    public static writable(): PropertyFilter {
        return new WritableFilter();
    }

    /** The properties of `kinds`: Collections, References… */
    public static ofKind(...kinds: ValueKind[]): PropertyFilter {
        return new KindFilter(kinds);
    }

    /** The properties holding `type`: a value object type, an entity type, a scalar type. */
    public static holding(type: string): PropertyFilter {
        return new HoldingFilter(type);
    }

    /** The properties under `path`, not the one at it: an owned object's fields. */
    public static under(path: string): PropertyFilter {
        return new UnderFilter(path);
    }

    /** The properties at `paths`. */
    public static paths(...paths: string[]): PropertyFilter {
        return new PathsFilter(paths);
    }

    /** The properties every one of `filters` accepts. */
    public static all(...filters: PropertyFilter[]): PropertyFilter {
        return new AllFilter(filters);
    }

    /** The properties one of `filters` accepts at least. */
    public static any(...filters: PropertyFilter[]): PropertyFilter {
        return new AnyFilter(filters);
    }
}
