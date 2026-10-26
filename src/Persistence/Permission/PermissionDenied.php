<?php

namespace XyloIsCoding\CoconutCms\Persistence\Permission;

use RuntimeException;

/**
 * Thrown by a save changing a field its actor may not write: nothing was
 * written. Holds every such field, by path.
 */
final class PermissionDenied extends RuntimeException
{
    /**
     * @param string $identifier The saved entity's type.
     * @param list<string> $paths Each field changed without the right: "price", "engine.horsepower".
     */
    public function __construct(
        public readonly string $identifier,
        public readonly array $paths,
    ) {
        parent::__construct(sprintf('This %s can\'t be saved: its actor may not change %s.', $identifier, implode(', ', array_map(static fn (string $path): string => '"' . $path . '"', $paths))));
    }
}
