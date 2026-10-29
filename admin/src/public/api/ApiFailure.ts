import {ErrorCode} from './ErrorCode.ts';

/**
 * Thrown by ApiClient.call() when an operation fails: the error the backend
 * answered with, or one standing for a call that never got an answer.
 */
export class ApiFailure extends Error {
    /**
     * @param code What went wrong, as the error table names it.
     * @param details What the code says more about: errors by path, the type
     *        refused, as the backend sends them.
     * @param status The HTTP status; 0 when no answer came.
     */
    public constructor(
        message: string,
        public readonly code: ErrorCode,
        public readonly details: Readonly<Record<string, unknown>>,
        public readonly status: number,
    ) {
        super(message);
        this.name = 'ApiFailure';
    }

    /** The message for one input path, from an invalid_input's details; undefined when there's none. */
    public errorAt(path: string): string | undefined {
        const errors = this.details['errors'];
        if (typeof errors !== 'object' || errors === null) {
            return undefined;
        }

        const message = (errors as Record<string, unknown>)[path];

        return typeof message === 'string' ? message : undefined;
    }
}
