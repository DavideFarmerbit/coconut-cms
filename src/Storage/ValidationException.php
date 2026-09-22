<?php

namespace XyloIsCoding\CoconutCms\Storage;

use RuntimeException;

/** Thrown when a value fails a FieldValidator, a unique field's value is already taken, or an id doesn't exist. */
final class ValidationException extends RuntimeException
{
}
