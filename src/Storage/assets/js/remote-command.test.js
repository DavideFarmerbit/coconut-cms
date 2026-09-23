import {describe, expect, it, vi} from 'vitest';
import {RemoteCommand} from './remote-command.js';

describe('RemoteCommand', () => {
    it('calls transport.undo with its own operationId', async () => {
        const transport = {undo: vi.fn().mockResolvedValue('op-2')};
        const command = new RemoteCommand('op-1', transport);

        await command.undo();

        expect(transport.undo).toHaveBeenCalledWith('op-1');
    });

    it('resolves to a new RemoteCommand wrapping the operationId the undo itself produced', async () => {
        const transport = {undo: vi.fn().mockResolvedValue('op-2')};
        const command = new RemoteCommand('op-1', transport);

        const redoCommand = await command.undo();

        expect(redoCommand).toBeInstanceOf(RemoteCommand);
        expect(redoCommand.operationId).toBe('op-2');
    });

    it('redoing (undoing the undo) targets the second operation, not the original', async () => {
        const transport = {undo: vi.fn().mockResolvedValueOnce('op-2').mockResolvedValueOnce('op-3')};
        const command = new RemoteCommand('op-1', transport);

        const redoCommand = await command.undo();
        await redoCommand.undo();

        expect(transport.undo).toHaveBeenNthCalledWith(2, 'op-2');
    });

    it('propagates a rejected transport call instead of swallowing it', async () => {
        const transport = {undo: vi.fn().mockRejectedValue(new Error('conflict'))};
        const command = new RemoteCommand('op-1', transport);

        await expect(command.undo()).rejects.toThrow('conflict');
    });
});
