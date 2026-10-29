/** Where a property stands against what was saved. */
export interface PropertyStatus {
    /** It has nothing saved to stand against: a new Collection item, or a property inside one. */
    readonly isNew: boolean;
    /** A Collection item's index when saved, when it had another; null otherwise. */
    readonly movedFrom: number | null;
    /** Its value differs from what was saved; a new one's always does. */
    readonly isChanged: boolean;
}
