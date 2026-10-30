<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use InvalidArgumentException;

/** Turns a stored identifier into the native class an object of it is built as. */
interface EntityClasses
{
    /**
     * The class an entity of $identifier is built as. Throws when it has none.
     * @return class-string
     * @throws InvalidArgumentException
     */
    public function classOf(string $identifier): string;
}
