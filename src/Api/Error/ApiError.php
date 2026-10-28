<?php

namespace XyloIsCoding\CoconutCms\Api\Error;

use Throwable;
use XyloIsCoding\CoconutCms\Api\Auth\CsrfRejected;
use XyloIsCoding\CoconutCms\Api\Auth\SignInFailed;
use XyloIsCoding\CoconutCms\Api\Auth\TooManyAttempts;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationUnfinished;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\MigrationPending;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\PersistenceUnavailable;

/**
 * A failed operation as every transport reports it: a code, a message for
 * people, and details for code. Over HTTP it's the body's "error".
 */
final readonly class ApiError
{
    /** @param array<string, mixed> $details What the code says more about, JSON-safe. */
    public function __construct(
        public ErrorCode $code,
        public string $message,
        public array $details = [],
    ) {
    }

    /**
     * What $failure is reported as. Anything not known is "internal": its
     * message is shown only in debug mode, since it may tell too much.
     */
    public static function of(Throwable $failure, bool $debug): self {
        return match (true) {
            $failure instanceof InvalidInput => new self(ErrorCode::InvalidInput, $failure->getMessage(), ['errors' => $failure->errors]),
            $failure instanceof PermissionDenied => new self(ErrorCode::PermissionDenied, $failure->getMessage(), [
                'type' => $failure->identifier,
                'action' => $failure->action?->value,
                'paths' => $failure->paths,
            ]),
            $failure instanceof SignInFailed => new self(ErrorCode::SignInFailed, $failure->getMessage()),
            $failure instanceof TooManyAttempts => new self(ErrorCode::TooManyRequests, $failure->getMessage()),
            $failure instanceof CsrfRejected => new self(ErrorCode::CsrfFailed, $failure->getMessage()),
            $failure instanceof Forbidden => new self(ErrorCode::PermissionDenied, $failure->getMessage()),
            $failure instanceof NotFound => new self(ErrorCode::NotFound, $failure->getMessage(), ['kind' => $failure->kind, 'key' => $failure->key]),
            $failure instanceof ValidationFailed => new self(ErrorCode::ValidationFailed, $failure->getMessage(), ['type' => $failure->identifier, 'errors' => $failure->errors]),
            $failure instanceof PersistenceUnavailable => new self(ErrorCode::Unavailable, $failure->getMessage(), ['reason' => match (true) {
                $failure instanceof MigrationPending => 'migration_pending',
                $failure instanceof MigrationUnfinished => 'migration_unfinished',
                default => 'unknown',
            }]),
            $debug => new self(ErrorCode::Internal, $failure->getMessage(), [
                'exception' => $failure::class,
                'at' => $failure->getFile() . ':' . $failure->getLine(),
            ]),
            default => new self(ErrorCode::Internal, 'Something went wrong on the server.'),
        };
    }
}
