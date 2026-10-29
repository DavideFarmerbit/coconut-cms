/**
 * Paths of properties, the way the API names them: an Embed's fields joined
 * with ":", an owned object's fields and a Collection's items with ".":
 * "chassis:weight", "engine.horsepower", "wheels.1.size".
 */
export class PropertyPath {
    /** Joins an Embed to its fields. */
    public static readonly EMBED = ':';

    /** Joins an owned object to its fields, and a Collection to its items. */
    public static readonly TRAVERSE = '.';

    /** `key` under `parent`, joined by `join`; `key` alone for a root field. */
    public static child(parent: string | null, join: string, key: string | number): string {
        return parent === null ? String(key) : `${parent}${join}${key}`;
    }

    /** Whether `path` lies under `ancestor`, never being it. */
    public static isUnder(path: string, ancestor: string): boolean {
        return path.length > ancestor.length
            && path.startsWith(ancestor)
            && (path[ancestor.length] === PropertyPath.EMBED || path[ancestor.length] === PropertyPath.TRAVERSE);
    }

    /** `path`'s segments: field names, and item indexes as numbers. */
    public static segments(path: string): readonly (string | number)[] {
        return path.split(/[:.]/).map((segment) => /^\d+$/.test(segment) ? Number(segment) : segment);
    }
}
