<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Resolver;

use InvalidArgumentException;

/**
 * Thrown by FieldValueResolver when nothing holds a value for a path: a miss,
 * as opposed to a path that doesn't fit the schema at all.
 */
final class NoFieldValueException extends InvalidArgumentException
{
}
