import {Layout} from './Layout.ts';
import type {LayoutStorage} from './LayoutStorage.ts';

/**
 * Keeps the layout in the browser's localStorage, one per user, so two users
 * of one browser don't share it. A layout that can't be read is dropped.
 */
export class LocalLayoutStorage implements LayoutStorage {
    /** @param userId Whose layout: part of the storage key. */
    public constructor(
        private readonly userId: string,
        private readonly storage: Storage = localStorage,
    ) {
    }

    public load(): Layout | null {
        const saved = this.storage.getItem(this.key());
        if (saved === null) {
            return null;
        }

        try {
            return Layout.fromData(JSON.parse(saved));
        } catch {
            return null;
        }
    }

    public save(layout: Layout): void {
        this.storage.setItem(this.key(), JSON.stringify(layout.toData()));
    }

    /** The storage key of this user's layout. */
    private key(): string {
        return `coconut-cms.layout.${this.userId}`;
    }
}
