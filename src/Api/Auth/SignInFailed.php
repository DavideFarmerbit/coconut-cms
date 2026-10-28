<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use RuntimeException;

/**
 * Thrown by Authenticator::signIn() when the credentials sign nobody in: an
 * unknown user, a wrong password, an address not verified. One message for
 * all, so a caller can't tell which users exist.
 */
final class SignInFailed extends RuntimeException
{
    public function __construct() {
        parent::__construct('Those credentials don\'t sign anyone in.');
    }
}
