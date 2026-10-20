<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use RuntimeException;

/**
 * Thrown when a stored schema was compiled against a dependency that has
 * changed since. The registry can't fix that on its own: recompiling needs
 * blueprints, which only a producer builds. Whoever gathers batches has to
 * recompile it.
 */
final class StaleSchemaException extends RuntimeException
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $dependency,
    ) {
        parent::__construct(sprintf(
            '"%s" is stale: "%s" changed since it was compiled, so it has to be recompiled.',
            $identifier,
            $dependency,
        ));
    }
}
