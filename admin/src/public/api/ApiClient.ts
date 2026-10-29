import {ApiFailure} from './ApiFailure.ts';
import {ErrorCode, isErrorCode} from './ErrorCode.ts';
import type {OperationName} from './OperationName.ts';

/** How the fetch function the client sends with is called: the browser's own, or a test's. */
export type Fetcher = (url: string, init: RequestInit) => Promise<Response>;

/**
 * Calls the backend's operations over HTTP, the way every part of the admin
 * and every plugin reaches it: a POST of the input as JSON, with the
 * session's cookie and CSRF token. Resolves to the result, or rejects with
 * an ApiFailure.
 */
export class ApiClient {
    /** The header the CSRF token travels in. */
    public static readonly CSRF_HEADER = 'X-CSRF-Token';

    /**
     * @param baseUrl Where operations are mounted: "/api".
     * @param csrfToken The session's token, as the admin page handed it over.
     * @param fetcher What sends a request: the browser's fetch unless given.
     */
    public constructor(
        private readonly baseUrl: string,
        private readonly csrfToken: string,
        private readonly fetcher: Fetcher = (url, init) => fetch(url, init),
    ) {
    }

    /**
     * Runs `operation` with `input`. The result's type is the caller's to
     * name: the backend's result class says what it is.
     */
    public async call<Result>(operation: OperationName, input: Readonly<Record<string, unknown>> = {}): Promise<Result> {
        let response: Response;
        try {
            response = await this.fetcher(`${this.baseUrl.replace(/\/$/, '')}/${operation}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', [ApiClient.CSRF_HEADER]: this.csrfToken},
                body: JSON.stringify(input),
            });
        } catch (failure) {
            throw new ApiFailure(`The backend can't be reached: ${String(failure)}`, ErrorCode.Unavailable, {}, 0);
        }

        const body = await ApiClient.bodyOf(response);
        if ('result' in body) {
            return body['result'] as Result;
        }

        throw ApiClient.failureOf(body, response.status);
    }

    /** The JSON object `response` holds; an empty one when it holds none. */
    private static async bodyOf(response: Response): Promise<Record<string, unknown>> {
        try {
            const body: unknown = await response.json();

            return typeof body === 'object' && body !== null ? body as Record<string, unknown> : {};
        } catch {
            return {};
        }
    }

    /** The ApiFailure `body`'s error stands for: an internal one when it holds none the client knows. */
    private static failureOf(body: Record<string, unknown>, status: number): ApiFailure {
        const error = body['error'];
        if (typeof error !== 'object' || error === null) {
            return new ApiFailure(`The backend answered ${status} with no error the admin can read.`, ErrorCode.Internal, {}, status);
        }

        const {code, message, details} = error as Record<string, unknown>;

        return new ApiFailure(
            typeof message === 'string' ? message : 'The backend failed without saying why.',
            isErrorCode(code) ? code : ErrorCode.Internal,
            typeof details === 'object' && details !== null ? details as Record<string, unknown> : {},
            status,
        );
    }
}
