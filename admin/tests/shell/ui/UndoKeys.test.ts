// @vitest-environment happy-dom
import {describe, expect, it} from 'vitest';
import {type KeyPress, UndoKeys} from '../../../src/shell/ui/UndoKeys.ts';

/** `key` pressed with the modifiers `changes` holds. */
function press(key: string, changes: Partial<KeyPress> = {}): KeyPress {
    return {key, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, ...changes};
}

describe('UndoKeys', () => {
    it('reads Cmd or Ctrl and Z as undo, with Shift as redo', () => {
        expect(UndoKeys.actionOf(press('z', {metaKey: true}))).toBe('undo');
        expect(UndoKeys.actionOf(press('z', {ctrlKey: true}))).toBe('undo');
        expect(UndoKeys.actionOf(press('Z', {metaKey: true, shiftKey: true}))).toBe('redo');
        expect(UndoKeys.actionOf(press('z', {ctrlKey: true, shiftKey: true}))).toBe('redo');
    });

    it('reads Ctrl and Y as redo too', () => {
        expect(UndoKeys.actionOf(press('y', {ctrlKey: true}))).toBe('redo');
        expect(UndoKeys.actionOf(press('y', {metaKey: true}))).toBeNull();
    });

    it('reads nothing else', () => {
        expect(UndoKeys.actionOf(press('z'))).toBeNull();
        expect(UndoKeys.actionOf(press('z', {metaKey: true, altKey: true}))).toBeNull();
        expect(UndoKeys.actionOf(press('s', {metaKey: true}))).toBeNull();
    });

    it('leaves a field being typed in to its own undo', () => {
        const input = (type: string): HTMLInputElement => Object.assign(document.createElement('input'), {type});
        const editable = document.createElement('div');
        editable.contentEditable = 'true';

        expect(UndoKeys.isTyping(input('text'))).toBe(true);
        expect(UndoKeys.isTyping(input('number'))).toBe(true);
        expect(UndoKeys.isTyping(document.createElement('textarea'))).toBe(true);
        expect(UndoKeys.isTyping(editable)).toBe(true);

        expect(UndoKeys.isTyping(input('checkbox'))).toBe(false);
        expect(UndoKeys.isTyping(document.createElement('button'))).toBe(false);
        expect(UndoKeys.isTyping(document.createElement('div'))).toBe(false);
        expect(UndoKeys.isTyping(null)).toBe(false);
    });
});
