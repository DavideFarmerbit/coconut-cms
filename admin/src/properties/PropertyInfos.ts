import type {PropertyInfo} from '../public/properties/PropertyInfo.ts';
import {PropertyPath} from '../public/properties/PropertyPath.ts';
import {isObjectValue} from '../public/properties/RecordValues.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';

/**
 * Describes an entity's properties by path, from its type and the values it
 * holds now: an owned object is described by the class it holds, so a
 * subtype's own fields are found.
 */
export class PropertyInfos {
    /**
     * @param values The entity's values now, read when asked: they change.
     */
    public constructor(
        private readonly catalog: TypeCatalog,
        private readonly type: string,
        private readonly values: () => Readonly<Record<string, unknown>>,
    ) {
    }

    /** The property at `path`; undefined when there is none: a field unknown, an item past the end, an empty object. */
    public of(path: string): PropertyInfo | undefined {
        let info: PropertyInfo | undefined;
        let current: unknown = this.values();
        for (const [depth, segment] of PropertyPath.segments(path).entries()) {
            if (info?.value.kind === 'collection') {
                if (typeof segment !== 'number' || !Array.isArray(current) || info.value.item === null || segment >= current.length) {
                    return undefined;
                }
                info = {path, field: null, value: info.value.item, holderType: null, writable: info.writable};
                current = current[segment];
                continue;
            }

            const holder = depth === 0 ? {type: this.type, values: current as Readonly<Record<string, unknown>>} : isObjectValue(current) ? current : undefined;
            const field = holder === undefined ? undefined : this.catalog.fieldsOf(holder.type).find((each) => each.name === String(segment));
            if (holder === undefined || field === undefined) {
                return undefined;
            }
            info = {path, field: field.name, value: field.field.value, holderType: holder.type, writable: field.field.write};
            current = holder.values[field.name];
        }

        return info;
    }
}
