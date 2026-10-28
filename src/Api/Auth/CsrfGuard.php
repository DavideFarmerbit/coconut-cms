<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

/**
 * Keeps a browser session's CSRF token: the page serving a client hands it
 * over, and every API call over HTTP sends it back in the X-CSRF-Token
 * header. A call without it, or with another, is refused: a page on another
 * site can make the browser send the cookie, never the token.
 */
interface CsrfGuard
{
    /** The header every call over HTTP sends the token in. */
    public const HEADER = 'X-CSRF-Token';

    /** The current session's token, made on first use. */
    public function token(): string;

    /** Whether $token is the current session's. */
    public function isValid(?string $token): bool;
}
