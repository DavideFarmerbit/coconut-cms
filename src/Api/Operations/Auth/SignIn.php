<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Auth;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;

/**
 * Signs the caller in through the project's Authenticator: the session the
 * browser holds is that user's from the next call on. Gives who signed in.
 */
#[Operation('coconut-cms:auth.signIn')]
final readonly class SignIn
{
    public function run(SignInInput $input, OperationContext $context): Me {
        return new Me($context->authenticator->signIn($input->login, $input->password, $input->remember));
    }
}
