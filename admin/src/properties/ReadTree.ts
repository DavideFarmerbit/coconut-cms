import {isObjectValue} from '../public/properties/RecordValues.ts';
import {PropertyPath} from '../public/properties/PropertyPath.ts';

/**
 * Finds and replaces values in an entity's values, in the form a read
 * gives, by path: a field name enters an object's values, an index a
 * Collection's items.
 */
export class ReadTree {
    /** The value at `path` in `values`; undefined when there's none. */
    public static at(values: Readonly<Record<string, unknown>>, path: string): unknown {
        let current: unknown = values;
        for (const segment of PropertyPath.segments(path)) {
            current = ReadTree.step(current, segment, current === values);
            if (current === undefined) {
                return undefined;
            }
        }

        return current;
    }

    /** Puts `value` at `path` in `values`; nothing when the place holding it is gone. */
    public static put(values: Record<string, unknown>, path: string, value: unknown): void {
        const segments = PropertyPath.segments(path);
        const last = segments[segments.length - 1];
        let holder: unknown = values;
        for (const segment of segments.slice(0, -1)) {
            holder = ReadTree.step(holder, segment, holder === values);
        }

        if (Array.isArray(holder) && typeof last === 'number') {
            holder[last] = value;
        } else if (typeof last === 'string' && (holder === values || isObjectValue(holder))) {
            (holder === values ? values : (holder as {values: Record<string, unknown>}).values)[last] = value;
        }
    }

    /** What `segment` reaches from `current`: an item, an object's field, the root's field. */
    private static step(current: unknown, segment: string | number, isRoot: boolean): unknown {
        if (Array.isArray(current)) {
            return typeof segment === 'number' ? current[segment] : undefined;
        }

        if (isRoot) {
            return (current as Record<string, unknown>)[String(segment)];
        }

        return isObjectValue(current) ? current.values[String(segment)] : undefined;
    }
}
