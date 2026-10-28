<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

/**
 * Keeps the CSRF token in the PHP session, starting it when needed: the
 * default CsrfGuard. Signing in or out keeps it, so a page's token stays
 * good across them.
 */
final readonly class SessionCsrf implements CsrfGuard
{
    /** Where the token is kept in $_SESSION. */
    private const KEY = 'coconut-cms.csrf';

    /** How many random bytes a token holds. */
    private const BYTES = 32;

    public function token(): string {
        self::start();

        return $_SESSION[self::KEY] ??= bin2hex(random_bytes(self::BYTES));
    }

    public function isValid(?string $token): bool {
        self::start();
        $expected = $_SESSION[self::KEY] ?? null;

        return is_string($expected) && $token !== null && hash_equals($expected, $token);
    }

    /** Starts the PHP session unless it's running. */
    private static function start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
