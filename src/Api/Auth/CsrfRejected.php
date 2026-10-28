<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use RuntimeException;

/** Thrown for a call over HTTP whose X-CSRF-Token header is missing, or not the session's. */
final class CsrfRejected extends RuntimeException
{
    public function __construct() {
        parent::__construct('The CSRF token is missing or stale: reload the page for a new one.');
    }
}
