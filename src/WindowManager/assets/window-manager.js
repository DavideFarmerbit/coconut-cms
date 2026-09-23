import {CommandStack} from '../../Storage/assets/js/command-stack.js';
import {EditingContexts} from '../../Storage/assets/js/editing-contexts.js';
import {LocalCommand} from '../../Storage/assets/js/local-command.js';
import {RemoteCommand} from '../../Storage/assets/js/remote-command.js';

/**
 * The entry point for everything that runs inside a CMS admin window: one shared
 * EditingContexts registry for the whole running admin app, one CommandStack per open
 * content tab, plus the command building blocks a tab needs to push onto its own
 * stack.
 *
 * No DOM or keyboard wiring here yet, there's no admin editor page built to attach it
 * to. This only composes the tested building blocks a future tab UI will drive.
 */
export {CommandStack, LocalCommand, RemoteCommand};

export const editingContexts = new EditingContexts();
