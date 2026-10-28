<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Auth;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** auth.signIn's input: the credentials. */
final readonly class SignInInput implements OperationInput
{
    /**
     * @param string $login An email or a username, as the project allows.
     * @param bool $remember Whether the sign-in outlives the browser session.
     */
    public function __construct(
        public string $login,
        public string $password,
        public bool $remember = false,
    ) {
    }
}
