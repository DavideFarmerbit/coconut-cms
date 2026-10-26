<?php

namespace XyloIsCoding\CoconutCms\Persistence\Permission;

use RuntimeException;

/**
 * Thrown when an actor does what it may not: an action on a type, or a save
 * changing fields. Nothing was read or written.
 */
final class PermissionDenied extends RuntimeException
{
    /**
     * @param string $identifier The entity's type.
     * @param TypeAction|null $action The action on the type refused; null when fields were.
     * @param list<string> $paths Each field changed without the right: "price", "engine.horsepower".
     */
    private function __construct(
        public readonly string $identifier,
        public readonly ?TypeAction $action,
        public readonly array $paths,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * A save changed fields $paths of a $identifier without the right to write them.
     * @param list<string> $paths
     */
    public static function ofFields(string $identifier, array $paths): self {
        return new self($identifier, null, $paths, sprintf(
            'This %s can\'t be saved: its actor may not change %s.',
            $identifier,
            implode(', ', array_map(static fn (string $path): string => '"' . $path . '"', $paths)),
        ));
    }

    /** The actor may not do $action to a $identifier. */
    public static function ofType(string $identifier, TypeAction $action): self {
        return new self($identifier, $action, [], sprintf('Its actor may not %s a %s.', $action->value, $identifier));
    }
}
