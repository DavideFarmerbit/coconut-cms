import {describe, expect, it} from 'vitest';
import {FakeBackend, storeOf} from '../../FakeBackend.ts';
import {Auth} from '../../../src/public/auth/Auth.ts';
import type {UnsavedSchemaAnswer, UnsavedSchemaQuestion} from '../../../src/schema/save/UnsavedSchemaQuestion.ts';
import {SchemaEditing} from '../../../src/tools/schema/SchemaEditing.ts';
import {shopSchema} from '../../schema/draft/ShopSchema.ts';

/** A question keeping the user signed in, counting how often it was asked. */
class Staying implements UnsavedSchemaQuestion {
    public asked = 0;

    public ask(): Promise<UnsavedSchemaAnswer> {
        this.asked++;

        return Promise.resolve('cancel');
    }

    public review(): Promise<boolean> {
        return Promise.resolve(false);
    }
}

/** The schema editing of ada, signed in on a backend serving the shop. */
async function editing(denied = false): Promise<{backend: FakeBackend; auth: Auth; question: Staying; editing: SchemaEditing}> {
    const backend = new FakeBackend();
    backend.schema = shopSchema;
    backend.schemaDenied = denied;
    const client = backend.client();
    const auth = new Auth(client);
    await auth.signIn('ada', 'lovelace', false);
    const question = new Staying();

    return {backend, auth, question, editing: new SchemaEditing(client, auth, storeOf(backend), question)};
}

describe('SchemaEditing', () => {
    it('loads one session for every view, and guards signing out with it until disposed of', async () => {
        const {backend, auth, question, editing: schema} = await editing();
        const session = await schema.session();

        expect(await schema.session()).toBe(session);
        expect(backend.count('coconut-cms:schema.get')).toBe(1);
        session!.draft.renameType(session!.draft.typeNamed('shop:boat')!.id, 'shop:skiff');
        expect(schema.hasUnsaved).toBe(true);

        expect(await auth.signOut()).toBe(false);
        expect(question.asked).toBe(1);

        schema.dispose();
        expect(await auth.signOut()).toBe(true);
    });

    it('gives no session to a user without the schema-editor role', async () => {
        const {editing: schema} = await editing(true);

        expect(await schema.session()).toBeNull();
        expect(schema.hasUnsaved).toBe(false);
    });
});
