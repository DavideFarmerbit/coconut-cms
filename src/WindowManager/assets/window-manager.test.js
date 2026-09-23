import {describe, expect, it} from 'vitest';
import {CommandStack, LocalCommand, RemoteCommand, editingContexts} from './window-manager.js';

describe('window-manager entry point', () => {
    it('re-exports the command building blocks', () => {
        expect(CommandStack).toBeDefined();
        expect(LocalCommand).toBeDefined();
        expect(RemoteCommand).toBeDefined();
    });

    it('exposes one shared EditingContexts registry', () => {
        expect(editingContexts.stackFor('tab-1')).toBeInstanceOf(CommandStack);
        expect(editingContexts.stackFor('tab-1')).toBe(editingContexts.stackFor('tab-1'));
    });
});
