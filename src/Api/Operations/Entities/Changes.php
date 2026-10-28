<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\InvalidChange;

/** How the entity-writing operations report a change they can't apply. */
final class Changes
{
    /** $invalid as the input error it is: at "changes.<path>", or at the input as a whole. */
    public static function invalid(InvalidChange $invalid): InvalidInput {
        return InvalidInput::at($invalid->path === '' ? '' : 'changes.' . $invalid->path, $invalid->getMessage());
    }
}
