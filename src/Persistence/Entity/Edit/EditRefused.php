<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Edit;

use RuntimeException;

/**
 * Thrown by a setter or afterEdit() refusing an edit, with a message for
 * whoever made it: "A car needs a name.". The edit reports it as a
 * ValidationFailed, at the path changed, or at the object afterEdit() runs
 * on. Nothing is saved.
 */
final class EditRefused extends RuntimeException
{
}
