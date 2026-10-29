import {ContextMenu} from '../../dom/ContextMenu.ts';
import {Elements} from '../../dom/Elements.ts';
import {Tooltips} from '../../dom/Tooltips.ts';
import type {TabOpening} from '../../public/workspace/TabOpening.ts';
import type {Workspace} from '../../public/workspace/Workspace.ts';
import {TypesTool} from './TypesTool.ts';

/** Told that a type link opened its type. */
export type TypeOpened = (identifier: string) => void;

/**
 * A link opening a type's inspector, the same everywhere: a click shows it
 * in the inspector tab shown most recently; Cmd or Ctrl and a click, a
 * middle click, or "Open in new tab" in its right-click menu open another
 * tab.
 */
export class TypeLink {
    /** A link to `identifier` reading `label`. */
    public static create(identifier: string, label: string, workspace: Workspace, onOpened?: TypeOpened): HTMLElement {
        // The full identifier as a tooltip, only where the label doesn't show it already.
        const link = Elements.create('button', {
            classes: ['type-link'],
            text: label,
            attributes: {type: 'button', ...(label === identifier ? {} : Tooltips.attributes(identifier, 'top'))},
        });
        TypeLink.attach(link, identifier, workspace, onOpened);

        return link;
    }

    /** Makes `element` open `identifier` the way a type link does. */
    public static attach(element: HTMLElement, identifier: string, workspace: Workspace, onOpened?: TypeOpened): void {
        const open = (opening: TabOpening): void => {
            TypesTool.open(workspace, identifier, opening);
            onOpened?.(identifier);
        };

        element.addEventListener('click', (event) => {
            event.stopPropagation();
            open(event.metaKey || event.ctrlKey ? 'new' : 'reuse');
        });
        element.addEventListener('auxclick', (event) => {
            if (event.button === 1) {
                event.preventDefault();
                open('new');
            }
        });
        element.addEventListener('contextmenu', (event) => {
            event.preventDefault();
            ContextMenu.show(event.clientX, event.clientY, [
                {label: 'Open', run: () => open('reuse')},
                {label: 'Open in new tab', run: () => open('new')},
            ]);
        });
    }
}
