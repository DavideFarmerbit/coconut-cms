<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

use RuntimeException;

/** Thrown when a change's expectedOperationId is stale: something logged after it already touched the same entity/fields. */
final class ConcurrentWriteException extends RuntimeException
{
}
