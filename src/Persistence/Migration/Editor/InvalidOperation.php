<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use RuntimeException;

/**
 * An editor save's operation that can't apply to the types as they are when
 * it runs: it names a type or field that doesn't exist, takes a name already
 * taken, or changes a native type. The save is refused, naming it.
 */
final class InvalidOperation extends RuntimeException
{
}
