import type {ValueDescription} from '../../public/types/TypeDescription.ts';

/** Puts a field's type in words: its short name for a row, its full description for a tooltip. */
export class ValueWords {
    /** What a scalar type is called in a row. */
    private static readonly SCALAR_NAMES: Readonly<Record<string, string>> = {
        string: 'string',
        text: 'text',
        int: 'int',
        float: 'float',
        decimal: 'decimal',
        bool: 'bool',
        date: 'date',
        date_time: 'date and time',
        enum: 'enum',
    };

    /** The type's short name: "int", "default:demo_turbo", "enum"; a Collection's items'. */
    public static nameOf(value: ValueDescription): string {
        const held = value.kind === 'collection' && value.item !== null ? value.item : value;
        switch (held.kind) {
            case 'scalar':
                return ValueWords.SCALAR_NAMES[held.scalarType ?? ''] ?? held.scalarType ?? '';
            case 'value_object':
                return 'value object';
            default:
                return held.target ?? '';
        }
    }

    /** The entity type it holds or points at, to link to; null for a value. */
    public static targetOf(value: ValueDescription): string | null {
        const held = value.kind === 'collection' && value.item !== null ? value.item : value;

        return held.kind === 'embed' || held.kind === 'reference' || held.kind === 'owning_reference' ? held.target : null;
    }

    /** Everything about it, in a sentence: what a tooltip says. */
    public static describe(value: ValueDescription): string {
        if (value.kind === 'collection' && value.item !== null) {
            return `A list of ${ValueWords.single(value.item, true)}${value.item.nullable ? '; an item may be empty' : ''}.`;
        }

        return `${ValueWords.capitalized(ValueWords.single(value, false))}${value.nullable ? '; may be empty' : ''}.`;
    }

    /** One value's description: plural for a Collection's items. */
    private static single(value: ValueDescription, plural: boolean): string {
        const s = plural ? 's' : '';
        switch (value.kind) {
            case 'scalar':
                return value.enumValues !== null
                    ? `${plural ? '' : 'one '}enum value${s} out of ${value.enumValues.join(', ')}`
                    : `${plural ? '' : 'a '}${ValueWords.nameOf(value)} value${s}`;
            case 'value_object':
                return `${plural ? '' : 'a '}value object${s}`;
            case 'embed':
                return `${plural ? '' : 'an '}embedded ${value.target}${s}, part of the entity`;
            case 'owning_reference':
                return `${plural ? '' : 'an '}owned ${value.target}${s} or subtypes, saved and deleted with the entity`;
            case 'reference':
                return `${plural ? 'references' : 'a reference'} to ${plural ? '' : 'a '}${value.target}${s} or subtypes, standing on their own`;
            default:
                return '';
        }
    }

    private static capitalized(text: string): string {
        return text.charAt(0).toUpperCase() + text.slice(1);
    }
}
