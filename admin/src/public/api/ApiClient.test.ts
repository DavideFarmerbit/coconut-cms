import {describe, expect, it} from 'vitest';
import {ApiClient, type Fetcher} from './ApiClient.ts';
import {ApiFailure} from './ApiFailure.ts';
import {ErrorCode} from './ErrorCode.ts';

/** A fetcher answering `body` with `status`, noting what it was asked. */
function answering(status: number, body: unknown, asked: {url?: string; init?: RequestInit} = {}): Fetcher {
    return async (url, init) => {
        asked.url = url;
        asked.init = init;

        return new Response(typeof body === 'string' ? body : JSON.stringify(body), {status});
    };
}

describe('ApiClient', () => {
    it('posts the input as JSON with the CSRF token, and resolves to the result', async () => {
        const asked: {url?: string; init?: RequestInit} = {};
        const client = new ApiClient('/api/', 'token-1', answering(200, {result: {user: null}}, asked));

        await expect(client.call('coconut-cms:auth.me', {a: 1})).resolves.toEqual({user: null});
        expect(asked.url).toBe('/api/coconut-cms:auth.me');
        expect(asked.init?.method).toBe('POST');
        expect(asked.init?.body).toBe('{"a":1}');
        expect((asked.init?.headers as Record<string, string>)[ApiClient.CSRF_HEADER]).toBe('token-1');
    });

    it('rejects with the backend\'s error', async () => {
        const client = new ApiClient('/api', 't', answering(400, {error: {code: 'invalid_input', message: 'Bad.', details: {errors: {limit: 'Must be an integer.'}}}}));

        const failure = await client.call('coconut-cms:entities.query').catch((error: unknown) => error);

        expect(failure).toBeInstanceOf(ApiFailure);
        expect((failure as ApiFailure).code).toBe(ErrorCode.InvalidInput);
        expect((failure as ApiFailure).status).toBe(400);
        expect((failure as ApiFailure).errorAt('limit')).toBe('Must be an integer.');
        expect((failure as ApiFailure).errorAt('type')).toBeUndefined();
    });

    it('rejects as internal when the answer holds no error it can read', async () => {
        const client = new ApiClient('/api', 't', answering(502, '<html>Bad gateway</html>'));

        await expect(client.call('coconut-cms:auth.me')).rejects.toMatchObject({code: ErrorCode.Internal, status: 502});
    });

    it('rejects as unavailable when no answer comes', async () => {
        const client = new ApiClient('/api', 't', async () => {
            throw new TypeError('Failed to fetch');
        });

        await expect(client.call('coconut-cms:auth.me')).rejects.toMatchObject({code: ErrorCode.Unavailable, status: 0});
    });
});
