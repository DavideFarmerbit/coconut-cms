/**
 * The schema draft's ids, each its own type: a type object's id can't be
 * passed where a field object's is expected. Made by the draft, they never
 * change, whatever its name becomes: every edit and every default path
 * holds an object by its id.
 */
declare const brand: unique symbol;

/** A type object's id. */
export type TypeId = string & {readonly [brand]: 'TypeId'};

/** A field object's id. */
export type FieldId = string & {readonly [brand]: 'FieldId'};

/** Makes the ids of one draft, each new. */
export class DraftIds {
    private next = 1;

    /** A new type object's id. */
    public type(): TypeId {
        return `type-${this.next++}` as TypeId;
    }

    /** A new field object's id. */
    public field(): FieldId {
        return `field-${this.next++}` as FieldId;
    }
}
