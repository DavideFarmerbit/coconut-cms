import {isObjectValue} from '../public/properties/RecordValues.ts';

/** Compares values in the form a read gives: plain JSON, so equal means the same content, whatever order keys were written in. */
export class Values {
    /** Whether `a` and `b` hold the same content. */
    public static equal(a: unknown, b: unknown): boolean {
        return Values.compare(a, b, false);
    }

    /**
     * Whether `a` and `b` hold the same content, owned objects' ids aside:
     * whether a value is at a default, whose owned objects have no id yet.
     */
    public static sameContent(a: unknown, b: unknown): boolean {
        return Values.compare(a, b, true);
    }

    private static compare(a: unknown, b: unknown, ignoreIds: boolean): boolean {
        if (a === b) {
            return true;
        }

        if (Array.isArray(a) || Array.isArray(b)) {
            return Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((item, index) => Values.compare(item, b[index], ignoreIds));
        }

        if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) {
            // A missing value and null are the same: neither holds anything.
            return (a ?? null) === (b ?? null);
        }

        if (ignoreIds && isObjectValue(a) && isObjectValue(b)) {
            return a.type === b.type && Values.compare(a.values, b.values, ignoreIds);
        }

        const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

        return [...keys].every((key) => Values.compare((a as Record<string, unknown>)[key], (b as Record<string, unknown>)[key], ignoreIds));
    }
}
