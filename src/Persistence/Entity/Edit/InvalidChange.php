<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Edit;

use InvalidArgumentException;

/**
 * Thrown by an edit by path given a change it can't apply: a path no field
 * has, or the reader can't read, or a value its field can't hold. Nothing
 * was saved.
 */
final class InvalidChange extends InvalidArgumentException
{
    /**
     * @param string $path The path of the value refused, from the entity; "" for the edit as a whole.
     * @param string $message Naming the path itself.
     */
    public function __construct(
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }
}
