<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Auth;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;

/** Who the caller is: what the admin asks first, to show a sign-in form or the app. */
#[Operation('coconut-cms:auth.me')]
final readonly class GetMe
{
    public function run(NoInput $input, OperationContext $context): Me {
        return Me::of($context->actor);
    }
}
