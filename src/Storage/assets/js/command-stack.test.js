import {describe, expect, it, vi} from 'vitest';
import {CommandStack} from './command-stack.js';
import {LocalCommand} from './local-command.js';
import {RemoteCommand} from './remote-command.js';

function localCommand(log, label) {
    return new LocalCommand(() => log.push(`undo ${label}`), () => log.push(`redo ${label}`));
}

describe('CommandStack', () => {
    it('undo with an empty stack does nothing', async () => {
        const stack = new CommandStack();

        await expect(stack.undo()).resolves.toBeUndefined();
    });

    it('undoes the most recently pushed command first', async () => {
        const log = [];
        const stack = new CommandStack();
        stack.push(localCommand(log, 'a'));
        stack.push(localCommand(log, 'b'));

        await stack.undo();

        expect(log).toEqual(['undo b']);
    });

    it('redo re-applies what was just undone', async () => {
        const log = [];
        const stack = new CommandStack();
        stack.push(localCommand(log, 'a'));

        await stack.undo();
        await stack.redo();

        expect(log).toEqual(['undo a', 'redo a']);
    });

    it('pushing a new command clears whatever was available to redo', async () => {
        const log = [];
        const stack = new CommandStack();
        stack.push(localCommand(log, 'a'));
        await stack.undo();

        stack.push(localCommand(log, 'b'));

        expect(stack.canRedo()).toBe(false);
    });

    it('canUndo/canRedo reflect stack state', async () => {
        const stack = new CommandStack();
        expect(stack.canUndo()).toBe(false);

        stack.push(localCommand([], 'a'));
        expect(stack.canUndo()).toBe(true);
        expect(stack.canRedo()).toBe(false);

        await stack.undo();
        expect(stack.canUndo()).toBe(false);
        expect(stack.canRedo()).toBe(true);
    });

    it('a failed undo puts the command back instead of losing it', async () => {
        const transport = {undo: vi.fn().mockRejectedValue(new Error('conflict'))};
        const stack = new CommandStack();
        const command = new RemoteCommand('op-1', transport);
        stack.push(command);

        await expect(stack.undo()).rejects.toThrow('conflict');

        expect(stack.canUndo()).toBe(true);
        expect(stack.canRedo()).toBe(false);
    });

    it('a failed redo puts the command back on the redo stack instead of losing it', async () => {
        const transport = {undo: vi.fn().mockResolvedValueOnce('op-2').mockRejectedValueOnce(new Error('conflict'))};
        const stack = new CommandStack();
        stack.push(new RemoteCommand('op-1', transport));
        await stack.undo();

        await expect(stack.redo()).rejects.toThrow('conflict');

        expect(stack.canRedo()).toBe(true);
        expect(stack.canUndo()).toBe(false);
    });

    it('mixes Local and Remote commands in the same stack transparently', async () => {
        const log = [];
        const transport = {undo: vi.fn().mockResolvedValue('op-2')};
        const stack = new CommandStack();
        stack.push(localCommand(log, 'a'));
        stack.push(new RemoteCommand('op-1', transport));

        await stack.undo();
        await stack.undo();

        expect(transport.undo).toHaveBeenCalledWith('op-1');
        expect(log).toEqual(['undo a']);
    });
});
