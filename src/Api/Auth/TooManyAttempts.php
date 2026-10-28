<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use RuntimeException;

/** Thrown by Authenticator::signIn() while signing in is throttled: too many attempts lately. */
final class TooManyAttempts extends RuntimeException
{
    public function __construct() {
        parent::__construct('Too many sign-in attempts: try again later.');
    }
}
