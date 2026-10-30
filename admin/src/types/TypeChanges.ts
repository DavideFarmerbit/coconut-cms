import {Values} from '../properties/Values.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {TypeChange} from '../public/types/TypeChange.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';

/** Works out what changed between two catalogs, and what that affects. */
export class TypeChanges {
    /** The change from `previous` to `next`; null when nothing changed. */
    public static between(previous: TypeCatalog, next: TypeCatalog): TypeChange | null {
        const identifiers = new Set([...previous.all(), ...next.all()].map((type) => type.identifier));
        const changed = new Set([...identifiers].filter((identifier) => !Values.equal(previous.get(identifier), next.get(identifier))));
        if (changed.size === 0) {
            return null;
        }

        return {previous, next, changed, affected: TypeChanges.affectedBy(changed, [previous, next])};
    }

    /**
     * `changed`, with their subtypes and the types holding them, embedded,
     * owned or as a Collection's items, again until no type joins, in every
     * one of `catalogs`: a type gone was held by types in the one before.
     */
    public static affectedBy(changed: ReadonlySet<string>, catalogs: readonly TypeCatalog[]): Set<string> {
        const affected = new Set(changed);
        let grew = true;
        while (grew) {
            grew = false;
            for (const catalog of catalogs) {
                for (const type of catalog.all()) {
                    const joins = !affected.has(type.identifier) && (
                        catalog.ancestorsOf(type.identifier).some((ancestor) => affected.has(ancestor.identifier))
                        || Object.values(type.fields).some((field) => TypeChanges.holds(field.value, affected))
                    );
                    if (joins) {
                        affected.add(type.identifier);
                        grew = true;
                    }
                }
            }
        }

        return affected;
    }

    /** Whether `value` holds one of `types` embedded or owned, itself or as a Collection's items. */
    private static holds(value: ValueDescription, types: ReadonlySet<string>): boolean {
        if (value.kind === 'collection') {
            return value.item !== null && TypeChanges.holds(value.item, types);
        }

        return (value.kind === 'embed' || value.kind === 'owning_reference') && value.target !== null && types.has(value.target);
    }
}
