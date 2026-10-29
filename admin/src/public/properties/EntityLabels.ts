import type {TypeCatalog} from '../types/TypeCatalog.ts';

/**
 * What an entity is called, for people: its field named `name`, `title` or
 * `label`, else its first String field, else its id. Until rendering hints
 * name a type's label field, this rule does.
 */
export class EntityLabels {
    /** The field names taken as a label first, in order. */
    private static readonly NAMES = ['name', 'title', 'label'] as const;

    /** The field of `type` its entities are called by; null when it has no String field. */
    public static fieldOf(catalog: TypeCatalog, type: string): string | null {
        const strings = catalog.fieldsOf(type)
            .filter(({field}) => field.read && field.value.kind === 'scalar' && (field.value.scalarType === 'string' || field.value.scalarType === 'text'))
            .map(({name}) => name);

        return EntityLabels.NAMES.find((name) => strings.includes(name)) ?? strings[0] ?? null;
    }

    /** What the `type` entity with `id` holding `values` is called. */
    public static of(catalog: TypeCatalog, type: string, id: string | null, values: Readonly<Record<string, unknown>>): string {
        const field = EntityLabels.fieldOf(catalog, type);
        const label = field === null ? undefined : values[field];

        return typeof label === 'string' && label !== '' ? label : id ?? `New ${type}`;
    }
}
