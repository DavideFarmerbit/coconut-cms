import {describe, expect, it} from 'vitest';
import {CommandStack} from './command-stack.js';
import {EditingContexts} from './editing-contexts.js';
import {LocalCommand} from './local-command.js';

describe('EditingContexts', () => {
    it('returns the same stack for the same context id', () => {
        const contexts = new EditingContexts();

        expect(contexts.stackFor('tab-1')).toBe(contexts.stackFor('tab-1'));
    });

    it('gives independent stacks to different context ids', () => {
        const contexts = new EditingContexts();

        expect(contexts.stackFor('tab-1')).toBeInstanceOf(CommandStack);
        expect(contexts.stackFor('tab-1')).not.toBe(contexts.stackFor('tab-2'));
    });

    it('undoing in one editing context never touches another, even for the same entity', async () => {
        const log = [];
        const contexts = new EditingContexts();

        contexts.stackFor('tab-1').push(new LocalCommand(() => log.push('undo tab-1'), () => {}));
        contexts.stackFor('tab-2').push(new LocalCommand(() => log.push('undo tab-2'), () => {}));

        await contexts.stackFor('tab-1').undo();

        expect(log).toEqual(['undo tab-1']);
        expect(contexts.stackFor('tab-2').canUndo()).toBe(true);
    });

    it('closing a context drops its stack, a fresh one starts empty', () => {
        const contexts = new EditingContexts();
        contexts.stackFor('tab-1').push(new LocalCommand(() => {}, () => {}));

        contexts.close('tab-1');

        expect(contexts.stackFor('tab-1').canUndo()).toBe(false);
    });
});
