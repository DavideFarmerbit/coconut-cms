<?php

namespace XyloIsCoding\CoconutCms\Storage\Permission;

use RuntimeException;

/** Thrown when an actor without SchemaPermission::canWrite() attempts to create a subclass or add/drop a column. */
final class SchemaPermissionDenied extends RuntimeException
{
}
