<?php

namespace XyloIsCoding\CoconutCms\Api\Error;

use RuntimeException;

/**
 * Thrown by an operation its caller may not run at all, whatever its input:
 * "Only an admin creates users.". Reported as permission_denied. A refusal
 * about a type or its fields is persistence's PermissionDenied instead.
 */
final class Forbidden extends RuntimeException
{
}
