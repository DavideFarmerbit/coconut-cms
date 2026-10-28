<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Auth;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;

/** Signs the caller out: nobody is signed in from the next call on. */
#[Operation('coconut-cms:auth.signOut')]
final readonly class SignOut
{
    public function run(NoInput $input, OperationContext $context): Me {
        $context->authenticator->signOut();

        return new Me(null);
    }
}
