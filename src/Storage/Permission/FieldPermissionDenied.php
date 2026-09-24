<?php

namespace XyloIsCoding\CoconutCms\Storage\Permission;

use RuntimeException;

/** Thrown when a changeset touches a field an actor lacks FieldPermission::canWrite() for. */
final class FieldPermissionDenied extends RuntimeException
{
}
