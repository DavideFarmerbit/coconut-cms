import type {FieldId, TypeId} from './DraftIds.ts';
import type {FieldRecord} from './FieldRecord.ts';
import type {TypeRecord} from './TypeRecord.ts';

/** Told that a schema draft changed: an edit done, undone or redone. */
export type DraftListener = () => void;

/**
 * Every type and field object of a schema draft, by id, in the order made:
 * what its edits replace. Tells its listeners after each edit.
 */
export class DraftRecords {
    private readonly types = new Map<TypeId, TypeRecord>();
    private readonly fields = new Map<FieldId, FieldRecord>();
    private readonly listeners: DraftListener[] = [];

    /** The type object `id`; undefined when there's none. */
    public type(id: TypeId): TypeRecord | undefined {
        return this.types.get(id);
    }

    /** The field object `id`; undefined when there's none. */
    public field(id: FieldId): FieldRecord | undefined {
        return this.fields.get(id);
    }

    /** Every type object, deleted ones included, in the order made. */
    public allTypes(): readonly TypeRecord[] {
        return [...this.types.values()];
    }

    /** Every field object, removed ones included, in the order made. */
    public allFields(): readonly FieldRecord[] {
        return [...this.fields.values()];
    }

    /** Puts `record` as the type object `id`; undefined takes it out: an edit creating it undone. */
    public putType(id: TypeId, record: TypeRecord | undefined): void {
        if (record === undefined) {
            this.types.delete(id);
        } else {
            this.types.set(id, record);
        }
    }

    /** Puts `record` as the field object `id`; undefined takes it out: an edit adding it undone. */
    public putField(id: FieldId, record: FieldRecord | undefined): void {
        if (record === undefined) {
            this.fields.delete(id);
        } else {
            this.fields.set(id, record);
        }
    }

    /** Tells `listener` after each edit. */
    public onChange(listener: DraftListener): void {
        this.listeners.push(listener);
    }

    /** Stops telling `listener`. */
    public offChange(listener: DraftListener): void {
        const index = this.listeners.indexOf(listener);
        if (index >= 0) {
            this.listeners.splice(index, 1);
        }
    }

    /** Tells every listener the draft changed. */
    public changed(): void {
        for (const listener of [...this.listeners]) {
            listener();
        }
    }
}
