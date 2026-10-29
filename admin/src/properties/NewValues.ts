import {isObjectValue, type ObjectValue} from '../public/properties/RecordValues.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';

/** What a new value starts as: a new owned object, Embed or Collection item, from its type's defaults where it has one. */
export class NewValues {
    /** A new object of `type`, holding its defaults, with no id yet. */
    public static object(catalog: TypeCatalog, type: string): ObjectValue {
        return {type, id: null, values: structuredClone({...(catalog.get(type)?.defaults ?? {})})};
    }

    /**
     * A new value of what `value` describes. A value starts empty, or at
     * zero, or at an enum's first value; an Embed and an owned object from
     * their target's defaults; a Reference and anything nullable at null.
     */
    public static of(catalog: TypeCatalog, value: ValueDescription): unknown {
        switch (value.kind) {
            case 'embed':
                return NewValues.object(catalog, value.target ?? '');
            case 'owning_reference':
                return value.nullable ? null : NewValues.object(catalog, value.target ?? '');
            case 'collection':
                return [];
            case 'reference':
                return null;
            case 'value_object':
                return value.nullable ? null : '';
            default:
                return value.nullable ? null : NewValues.scalar(value);
        }
    }

    /**
     * A copy of `value`, a default, keeping the ids of the owned objects
     * `current` holds where one of the same type stands: resetting an owned
     * object to its default resets its values, and keeps the object.
     */
    public static keepingIds(value: unknown, current: unknown): unknown {
        if (isObjectValue(value) && isObjectValue(current) && value.type === current.type) {
            return {
                type: value.type,
                id: current.id,
                values: Object.fromEntries(Object.entries(value.values).map(([name, each]) => [name, NewValues.keepingIds(each, current.values[name])])),
            };
        }

        return structuredClone(value);
    }

    /** A non-null scalar's starting value. */
    private static scalar(value: ValueDescription): unknown {
        const now = new Date().toISOString();
        switch (value.scalarType) {
            case 'int':
            case 'float':
                return 0;
            case 'decimal':
                return '0';
            case 'bool':
                return false;
            case 'date':
                return now.slice(0, 10);
            case 'date_time':
                return `${now.slice(0, 10)} ${now.slice(11, 19)}`;
            case 'enum':
                return value.enumValues?.[0] ?? null;
            default:
                return '';
        }
    }
}
