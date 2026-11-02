import type {Edit} from '../../public/edits/Edit.ts';
import type {DraftRecords} from './DraftRecords.ts';
import type {FieldId, TypeId} from './DraftIds.ts';
import type {FieldRecord} from './FieldRecord.ts';
import type {TypeRecord} from './TypeRecord.ts';

/** One object an edit replaces: what it was before the edit and after; undefined for none, made or unmade by it. */
export type RecordSwap =
    | {readonly kind: 'type'; readonly id: TypeId; readonly before: TypeRecord | undefined; readonly after: TypeRecord | undefined}
    | {readonly kind: 'field'; readonly id: FieldId; readonly before: FieldRecord | undefined; readonly after: FieldRecord | undefined};

/**
 * One edit to a schema draft: the type and field objects it replaced, each
 * as it was before and after. Records are never changed, only replaced, so
 * undoing puts back exactly the ones it replaced.
 */
export class DraftEdit implements Edit {
    public constructor(
        public readonly label: string,
        private readonly records: DraftRecords,
        private readonly swaps: readonly RecordSwap[],
    ) {
    }

    public apply(): void {
        for (const swap of this.swaps) {
            DraftEdit.put(this.records, swap, 'after');
        }
        this.records.changed();
    }

    public revert(): void {
        for (const swap of [...this.swaps].reverse()) {
            DraftEdit.put(this.records, swap, 'before');
        }
        this.records.changed();
    }

    /** Puts `swap`'s object as it was `when`. */
    private static put(records: DraftRecords, swap: RecordSwap, when: 'before' | 'after'): void {
        if (swap.kind === 'type') {
            records.putType(swap.id, swap[when]);
        } else {
            records.putField(swap.id, swap[when]);
        }
    }
}
