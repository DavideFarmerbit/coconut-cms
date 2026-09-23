import {describe, expect, it, vi} from 'vitest';
import {LocalCommand} from './local-command.js';

describe('LocalCommand', () => {
    it('calls the undo function when undone', () => {
        const undo = vi.fn();
        const command = new LocalCommand(undo, vi.fn());

        command.undo();

        expect(undo).toHaveBeenCalledOnce();
    });

    it('returns a command whose undo re-applies the original action', () => {
        const applied = [];
        const command = new LocalCommand(
            () => applied.push('reverted'),
            () => applied.push('reapplied'),
        );

        const redoCommand = command.undo();
        redoCommand.undo();

        expect(applied).toEqual(['reverted', 'reapplied']);
    });

    it('supports repeated undo/redo cycling without ever needing a separate redo mechanism', () => {
        const applied = [];
        let command = new LocalCommand(
            () => applied.push('reverted'),
            () => applied.push('reapplied'),
        );

        command = command.undo(); // reverted
        command = command.undo(); // reapplied
        command = command.undo(); // reverted
        command = command.undo(); // reapplied

        expect(applied).toEqual(['reverted', 'reapplied', 'reverted', 'reapplied']);
    });
});
