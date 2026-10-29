import type {EntitySession} from '../public/entities/EntitySession.ts';
import type {PropertyFilter} from '../public/properties/PropertyFilter.ts';
import {PropertyPath} from '../public/properties/PropertyPath.ts';
import {isObjectValue} from '../public/properties/RecordValues.ts';

/**
 * How a property shows under a filter:
 * - `full`: the filter accepts it or an ancestor of it; its row and all under it show;
 * - `parent`: the filter accepts something under it; a plain row, its name
 *   and its chevron, holds what shows under it;
 * - `hidden`: neither.
 */
export type Visibility = 'full' | 'parent' | 'hidden';

/** Decides how a session's properties show under a filter; with no filter, every property shows in full. */
export class PropertyVisibility {
    public constructor(
        private readonly session: EntitySession,
        private readonly filter: PropertyFilter | null,
    ) {
    }

    /** How the property at `path` shows. */
    public of(path: string): Visibility {
        if (this.filter === null) {
            return 'full';
        }

        if ([...PropertyVisibility.ancestorsOf(path), path].some((each) => this.accepted(each))) {
            return 'full';
        }

        return this.holdsAccepted(path) ? 'parent' : 'hidden';
    }

    /** The paths of the entity's fields the user may read. */
    public rootPaths(): string[] {
        const values = this.session.values;

        return (this.session.types?.fieldsOf(this.session.type) ?? [])
            .filter(({name, field}) => field.read && name in values)
            .map(({name}) => name);
    }

    /** The paths of the properties right under `path`: an object's fields the user may read, a Collection's items. */
    public childPaths(path: string): string[] {
        const info = this.session.describe(path);
        const value = this.session.valueAt(path);
        if (info === undefined) {
            return [];
        }

        if (info.value.kind === 'collection') {
            return Array.isArray(value) ? value.map((_item, index) => PropertyPath.child(path, PropertyPath.TRAVERSE, index)) : [];
        }

        if (!isObjectValue(value) || (info.value.kind !== 'embed' && info.value.kind !== 'owning_reference')) {
            return [];
        }

        // An Embed field's fields are embedded; an Embed item's, and an owned object's, traversed.
        const join = info.value.kind === 'embed' && info.field !== null ? PropertyPath.EMBED : PropertyPath.TRAVERSE;

        return (this.session.types?.fieldsOf(value.type) ?? [])
            .filter(({name, field}) => field.read && name in value.values)
            .map(({name}) => PropertyPath.child(path, join, name));
    }

    /** Whether the filter accepts the property at `path`. */
    private accepted(path: string): boolean {
        const info = this.session.describe(path);

        return info !== undefined && this.filter !== null && this.filter.accepts(info);
    }

    /** Whether the filter accepts a property anywhere under `path`. */
    private holdsAccepted(path: string): boolean {
        return this.childPaths(path).some((child) => this.accepted(child) || this.holdsAccepted(child));
    }

    /** The paths of `path`'s ancestors, the outermost first. */
    private static ancestorsOf(path: string): string[] {
        const ancestors: string[] = [];
        for (let index = 0; index < path.length; index++) {
            if (path[index] === PropertyPath.EMBED || path[index] === PropertyPath.TRAVERSE) {
                ancestors.push(path.slice(0, index));
            }
        }

        return ancestors;
    }
}
