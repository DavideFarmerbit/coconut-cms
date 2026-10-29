import {isObjectValue, isReferenceValue} from '../public/properties/RecordValues.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {ValueDescription} from '../public/types/TypeDescription.ts';

/**
 * Turns a value from the form a read gives into the form an update takes:
 * a Reference to its target's id, an Embed to `{values}`, an owned object to
 * `{type, id, values}`, its id only when it has one, a Collection item by
 * item. A value or a value object stays as it is.
 */
export class WriteForm {
    public constructor(private readonly catalog: TypeCatalog) {
    }

    /** `read`, a value of what `value` describes, as an update takes it. */
    public of(value: ValueDescription, read: unknown): unknown {
        if (read === null || read === undefined) {
            return null;
        }

        switch (value.kind) {
            case 'reference':
                return isReferenceValue(read) ? read.id : read;
            case 'embed':
                return isObjectValue(read) ? {values: this.valuesOf(read.type, read.values)} : read;
            case 'owning_reference':
                return isObjectValue(read)
                    ? {type: read.type, ...(read.id === null ? {} : {id: read.id}), values: this.valuesOf(read.type, read.values)}
                    : read;
            case 'collection':
                return Array.isArray(read) && value.item !== null ? read.map((item) => this.of(value.item as ValueDescription, item)) : read;
            default:
                return read;
        }
    }

    /** An object's `values`, each field's as an update takes it; a field `type` lacks is left out. */
    private valuesOf(type: string, values: Readonly<Record<string, unknown>>): Record<string, unknown> {
        const written: Record<string, unknown> = {};
        for (const {name, field} of this.catalog.fieldsOf(type)) {
            if (name in values) {
                written[name] = this.of(field.value, values[name]);
            }
        }

        return written;
    }
}
