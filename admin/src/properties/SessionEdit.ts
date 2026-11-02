import type {Edit} from '../public/edits/Edit.ts';
import type {SessionChange} from '../public/entities/SessionChange.ts';
import type {DraftState} from './DraftState.ts';
import type {EntityDraft} from './EntityDraft.ts';

/** What a session edit changed, told again to the views when it's undone or redone. */
export type EditedChange = Exclude<SessionChange, {readonly kind: 'state'}>;

/**
 * One change to an EntitySession's draft, undoable: the draft as it was
 * before and after, whole, so undoing puts back exactly what was there,
 * items' origins included. Undone or redone, its views are told the same
 * change it made, from no view, so every one shows it. A value typed letter
 * by letter is one edit: it absorbs the next value set at the same path.
 */
export class SessionEdit implements Edit {
    /**
     * @param change What it changed, to tell the views.
     * @param tell Tells the session's views a change.
     */
    public constructor(
        public readonly label: string,
        private readonly draft: EntityDraft,
        private readonly change: EditedChange,
        private readonly before: DraftState,
        private after: DraftState,
        private readonly tell: (change: SessionChange) => void,
    ) {
    }

    public apply(): void {
        this.draft.restore(this.after);
        this.told();
    }

    public revert(): void {
        this.draft.restore(this.before);
        this.told();
    }

    /** Takes `next` when both set the values at the same paths of one draft: what's typed in one field. */
    public absorb(next: Edit): boolean {
        if (!(next instanceof SessionEdit) || next.draft !== this.draft || this.change.kind !== 'values' || next.change.kind !== 'values') {
            return false;
        }

        if (this.change.paths.join('\n') !== next.change.paths.join('\n')) {
            return false;
        }

        this.after = next.after;

        return true;
    }

    /** Tells the views its change, from no view, and that the session's state changed. */
    private told(): void {
        this.tell(this.change.kind === 'reloaded' ? this.change : {...this.change, source: null});
        this.tell({kind: 'state'});
    }
}
