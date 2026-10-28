<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Auth;

use XyloIsCoding\CoconutCms\Api\Auth\User;
use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;
use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;

/** Who the caller is: the user signed in, with its roles; null for nobody. */
final readonly class Me implements OperationResult
{
    public function __construct(
        public ?User $user,
    ) {
    }

    /** $actor, as Me tells it: anyone but a User is nobody signed in. */
    public static function of(Actor $actor): self {
        return new self($actor instanceof User ? $actor : null);
    }
}
