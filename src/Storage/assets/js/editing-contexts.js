import {CommandStack} from './command-stack.js';

/**
 * The client-side stack isn't singular, it's one independent stack per open editing
 * context (content tab), not one per browser session, so Ctrl+Z on one tab never
 * reaches into another's history. This is just a map from a caller-chosen context id
 * (whatever the tab UI ends up using) to that context's own CommandStack, created on
 * first use.
 */
export class EditingContexts {
    #stacks = new Map();

    stackFor(contextId) {
        if (!this.#stacks.has(contextId)) {
            this.#stacks.set(contextId, new CommandStack());
        }

        return this.#stacks.get(contextId);
    }

    /** Closing a tab drops its stack entirely, nothing to undo for an editing context that no longer exists. */
    close(contextId) {
        this.#stacks.delete(contextId);
    }
}
