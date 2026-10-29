import type {EntitySession} from './EntitySession.ts';

/** What lets a hold go: the store that gave it. */
export interface HoldReleaser {
    /** Lets `hold` go; false when the user cancelled. */
    release(hold: EntityHold): Promise<boolean>;
}

/**
 * A view's hold on a shared EntitySession, from EntityStore.edit() or
 * create(). The store keeps the session while any hold on it stands. A view
 * releases its hold when it closes; a tab's canClose() is its release().
 */
export class EntityHold {
    private released = false;

    public constructor(
        /** The session held. */
        public readonly session: EntitySession,
        private readonly releaser: HoldReleaser,
    ) {
    }

    /** Whether it was let go. */
    public get isReleased(): boolean {
        return this.released;
    }

    /**
     * Lets the session go. The last hold on a session with unsaved changes
     * asks first: Save, Discard or Cancel.
     * @return Whether it was let go: false when the user cancelled, or the save failed.
     */
    public async release(): Promise<boolean> {
        if (this.released) {
            return true;
        }

        this.released = await this.releaser.release(this);

        return this.released;
    }
}
