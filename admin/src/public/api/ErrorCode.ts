/** Every code an API error carries, as the backend's error table lists them. */
export const ErrorCode = {
    InvalidInput: 'invalid_input',
    SignInFailed: 'sign_in_failed',
    PermissionDenied: 'permission_denied',
    CsrfFailed: 'csrf_failed',
    NotFound: 'not_found',
    ValidationFailed: 'validation_failed',
    TooManyRequests: 'too_many_requests',
    Unavailable: 'unavailable',
    Internal: 'internal',
    StaleRevision: 'stale_revision',
    SchemaSaveRefused: 'schema_save_refused',
} as const;

/** One of ErrorCode's values. */
export type ErrorCode = typeof ErrorCode[keyof typeof ErrorCode];

/** Whether `value` is one of ErrorCode's values. */
export function isErrorCode(value: unknown): value is ErrorCode {
    return Object.values(ErrorCode).includes(value as ErrorCode);
}
