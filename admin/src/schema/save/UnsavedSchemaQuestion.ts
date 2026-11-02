import type {SchemaSession} from './SchemaSession.ts';

/** An answer about unsaved schema edits: review them and save, drop them, or stay. */
export type UnsavedSchemaAnswer = 'review' | 'discard' | 'cancel';

/** Asks the admin about unsaved schema edits before they sign out: the schema editor's dialogs. */
export interface UnsavedSchemaQuestion {
    /** Review and save, Discard or Cancel. */
    ask(): Promise<UnsavedSchemaAnswer>;

    /** Shows `session`'s preview and lets the admin save it: whether nothing is left unsaved, saved or dropped. Closing it saves nothing. */
    review(session: SchemaSession): Promise<boolean>;
}
