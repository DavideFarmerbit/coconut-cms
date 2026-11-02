import {describe, expect, it} from 'vitest';
import type {Edit} from '../../../src/public/edits/Edit.ts';
import {EditHistory} from '../../../src/public/edits/EditHistory.ts';

/** A list of words an edit pushes onto, the way an editor's model changes. */
class Words {
    public readonly words: string[] = [];
}

/** Adds one word; reverted, takes it off again. */
class AddWord implements Edit {
    public readonly label: string;

    public constructor(private readonly target: Words, private readonly word: string) {
        this.label = `Add ${word}`;
    }

    public apply(): void {
        this.target.words.push(this.word);
    }

    public revert(): void {
        this.target.words.splice(this.target.words.lastIndexOf(this.word), 1);
    }
}

/** Sets the word at `index` from `before` to `after`; absorbs the next typed at the same index. */
class Typed implements Edit {
    public readonly label: string;

    public constructor(private readonly target: Words, private readonly index: number, private readonly before: string, private after: string) {
        this.label = `Type ${after}`;
    }

    public apply(): void {
        this.target.words[this.index] = this.after;
    }

    public revert(): void {
        this.target.words[this.index] = this.before;
    }

    public absorb(next: Edit): boolean {
        if (!(next instanceof Typed) || next.index !== this.index) {
            return false;
        }

        this.after = next.after;

        return true;
    }
}

describe('EditHistory', () => {
    it('undoes and redoes edits in turn', () => {
        const model = new Words();
        const history = new EditHistory();
        history.do(new AddWord(model, 'sea'));
        history.do(new AddWord(model, 'wind'));

        history.undo();
        expect(model.words).toEqual(['sea']);
        history.undo();
        expect(model.words).toEqual([]);
        history.redo();
        expect(model.words).toEqual(['sea']);
        history.redo();
        expect(model.words).toEqual(['sea', 'wind']);
    });

    it('names what undo and redo would do, and says when there is nothing', () => {
        const model = new Words();
        const history = new EditHistory();
        expect([history.canUndo(), history.canRedo(), history.undoLabel(), history.redoLabel()]).toEqual([false, false, null, null]);

        history.do(new AddWord(model, 'sea'));
        expect([history.canUndo(), history.undoLabel()]).toEqual([true, 'Add sea']);
        history.undo();
        expect([history.canUndo(), history.canRedo(), history.redoLabel()]).toEqual([false, true, 'Add sea']);

        history.undo();
        history.redo();
        history.redo();
        expect(model.words, 'Undoing or redoing past the ends does nothing.').toEqual(['sea']);
    });

    it('forgets what could be redone once a new edit is done', () => {
        const model = new Words();
        const history = new EditHistory();
        history.do(new AddWord(model, 'sea'));
        history.undo();
        history.do(new AddWord(model, 'calm'));

        expect(history.canRedo()).toBe(false);
        history.redo();
        expect(model.words).toEqual(['calm']);
    });

    it('lets the last edit absorb the next until sealed, undone or redone', () => {
        const model = new Words();
        const history = new EditHistory();
        model.words.push('s');
        history.record(new Typed(model, 0, '', 's'));
        model.words[0] = 'se';
        history.record(new Typed(model, 0, 's', 'se'));
        model.words[0] = 'sea';
        history.record(new Typed(model, 0, 'se', 'sea'));

        history.undo();
        expect(model.words, 'Recorded, not applied again; absorbed, undone at once.').toEqual(['']);
        history.redo();
        expect(model.words).toEqual(['sea']);

        history.do(new Typed(model, 0, 'sea', 'seas'));
        expect(history.undoLabel(), 'Redone, the last edit takes no more.').toBe('Type seas');

        history.seal();
        history.do(new Typed(model, 0, 'seas', 'seashore'));
        history.undo();
        expect(model.words).toEqual(['seas']);
    });

    it('forgets everything when cleared, and tells its listeners of each change', () => {
        const history = new EditHistory();
        let told = 0;
        const listener = (): void => {
            told++;
        };
        history.onChange(listener);

        history.do(new AddWord(new Words(), 'sea'));
        history.undo();
        history.clear();
        expect([history.canUndo(), history.canRedo(), told]).toEqual([false, false, 3]);

        history.clear();
        expect(told, 'Clearing nothing changes nothing.').toBe(3);
        history.offChange(listener);
        history.do(new AddWord(new Words(), 'wind'));
        expect(told).toBe(3);
    });
});
