import type {ValueDescription} from '../types/TypeDescription.ts';

/** One property of an entity, described: what views and filters know of it. */
export interface PropertyInfo {
    /** Its path from the entity: "chassis:weight", "wheels.1". */
    readonly path: string;
    /** Its field's name; null for a Collection's item. */
    readonly field: string | null;
    /** What it holds. */
    readonly value: ValueDescription;
    /** The type of the object holding it; null for a Collection's item. */
    readonly holderType: string | null;
    /** Whether the user may change it. */
    readonly writable: boolean;
}
