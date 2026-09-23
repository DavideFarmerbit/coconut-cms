/**
 * A command that already caused a server-side effect (a draft save, a publish, a
 * schema change), undoing it is a real network round trip, not an instant operation.
 *
 * `transport` is injected rather than this class making its own HTTP call, so it's
 * testable without a real server, and swappable for whatever the actual API surface
 * ends up being. It needs exactly one method, `undo(operationId)`, resolving to the
 * id of the new operation the undo itself produced, since the server computes and
 * flushes an inverse changeset rather than rolling back in place.
 *
 * undo() resolves to a new RemoteCommand wrapping that new operation id, so calling
 * undo() on *that* one redoes the original, the same undo-the-undo symmetry
 * LocalCommand uses, just asynchronous.
 */
export class RemoteCommand {
    #operationId;
    #transport;

    constructor(operationId, transport) {
        this.#operationId = operationId;
        this.#transport = transport;
    }

    get operationId() {
        return this.#operationId;
    }

    async undo() {
        const newOperationId = await this.#transport.undo(this.#operationId);

        return new RemoteCommand(newOperationId, this.#transport);
    }
}
