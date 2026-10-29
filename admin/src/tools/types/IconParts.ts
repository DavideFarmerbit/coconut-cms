import type {ValueDescription} from '../../public/types/TypeDescription.ts';

/** The frame, from multiplicity: a circle for one value, a grid for a Collection. */
export type IconFrame = 'single' | 'collection';

/** The glyph, from how the value is held: a value, an Embed, an owned object, a Reference. */
export type IconGlyph = 'value' | 'embed' | 'owned' | 'reference';

/** The color, from the value's type: each names a --cc-type-* theme token. */
export type IconColor = 'bool' | 'int' | 'float' | 'string' | 'date' | 'enum' | 'value-object' | 'entity';

/**
 * The layers a field's type icon is stacked from, each picked on its own:
 * so every combination is drawn from 2 frames, 4 glyphs, 1 badge and 8
 * colors, never drawn whole.
 */
export class IconParts {
    private constructor(
        public readonly frame: IconFrame,
        public readonly glyph: IconGlyph,
        public readonly color: IconColor,
        /** Whether it carries the "may be empty" badge. */
        public readonly nullable: boolean,
    ) {
    }

    /** The parts of `value`'s icon. A Collection is drawn with its items' glyph, color and nullability. */
    public static of(value: ValueDescription): IconParts {
        const held = value.kind === 'collection' && value.item !== null ? value.item : value;

        return new IconParts(value.kind === 'collection' ? 'collection' : 'single', IconParts.glyphOf(held), IconParts.colorOf(held), held.nullable);
    }

    private static glyphOf(value: ValueDescription): IconGlyph {
        switch (value.kind) {
            case 'embed':
                return 'embed';
            case 'owning_reference':
                return 'owned';
            case 'reference':
                return 'reference';
            default:
                return 'value';
        }
    }

    private static colorOf(value: ValueDescription): IconColor {
        if (value.kind === 'value_object') {
            return 'value-object';
        }

        if (value.kind !== 'scalar') {
            return 'entity';
        }

        switch (value.scalarType) {
            case 'bool':
                return 'bool';
            case 'int':
                return 'int';
            case 'float':
            case 'decimal':
                return 'float';
            case 'date':
            case 'date_time':
                return 'date';
            case 'enum':
                return 'enum';
            default:
                return 'string';
        }
    }
}
