<?php

namespace XyloIsCoding\CoconutCms\Api\Error;

/**
 * Every way an operation can fail, the same on every transport: its code in
 * the error body, its HTTP status, and the command line's exit status.
 */
enum ErrorCode: string
{
    case InvalidInput = 'invalid_input';
    case PermissionDenied = 'permission_denied';
    case NotFound = 'not_found';
    case ValidationFailed = 'validation_failed';
    case Unavailable = 'unavailable';
    case Internal = 'internal';

    /** The HTTP status an HTTP call answers with. */
    public function httpStatus(): int {
        return match ($this) {
            self::InvalidInput => 400,
            self::PermissionDenied => 403,
            self::NotFound => 404,
            self::ValidationFailed => 422,
            self::Unavailable => 503,
            self::Internal => 500,
        };
    }

    /** The status a command exits with: 0 is success, so never 0. */
    public function exitStatus(): int {
        return match ($this) {
            self::Internal => 1,
            self::InvalidInput => 2,
            self::PermissionDenied => 3,
            self::NotFound => 4,
            self::ValidationFailed => 5,
            self::Unavailable => 6,
        };
    }
}
