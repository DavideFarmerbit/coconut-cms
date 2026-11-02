// @vitest-environment happy-dom
import {describe, expect, it} from 'vitest';
import {carType, FakeBackend} from '../../FakeBackend.ts';
import {FakeContext} from '../../FakeContext.ts';
import {TypesView} from '../../../src/tools/types/TypesView.ts';

/** Lets pending promises run. */
async function settled(): Promise<void> {
    for (let round = 0; round < 10; round++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

/** What each entry of `view` reads, with its depth in the hierarchy when it has one. */
function entries(view: TypesView): string[] {
    return [...view.element.querySelectorAll<HTMLElement>('.list__item')].map((item) => {
        const depth = item.style.getPropertyValue('--depth');

        return depth === '' ? item.textContent! : `${depth} ${item.textContent}`;
    });
}

/** A backend whose types are the car, a sports car extending it, and the wheel and the bike. */
function backend(): FakeBackend {
    const made = new FakeBackend();
    made.types = [...made.types, {...carType, identifier: 'shop:sports', parent: 'shop:car'}];

    return made;
}

/** The panel's button reading `label`. */
function button(view: TypesView, label: string): HTMLButtonElement {
    return [...view.element.querySelectorAll<HTMLButtonElement>('button')].find((each) => each.textContent === label)!;
}

describe('TypesView', () => {
    it('lists the types as saved, by namespace, read only', async () => {
        const view = new TypesView(new FakeContext(backend()));
        await settled();

        expect(entries(view)).toEqual(['bike', 'car', 'sports', 'wheel']);
        expect(view.element.textContent).not.toContain('New type');
    });

    it('groups by hierarchy, each type under the one it extends, and remembers it', async () => {
        const context = new FakeContext(backend());
        const view = new TypesView(context);
        await settled();

        button(view, 'By hierarchy').click();
        expect(entries(view)).toEqual(['0 shop:bike', '0 shop:car', '1 shop:sports', '0 shop:wheel']);
        expect(context.state.get('grouping')).toBe('hierarchy');
    });

    it('opens a type\'s inspector', async () => {
        const context = new FakeContext(backend());
        const view = new TypesView(context);
        await settled();

        view.element.querySelector<HTMLElement>('[data-type="shop:wheel"]')!.click();
        expect(context.workspace.opened.map((tab) => tab.params)).toEqual([{type: 'shop:wheel'}]);
    });
});
