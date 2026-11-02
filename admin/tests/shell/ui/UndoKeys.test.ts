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
});
