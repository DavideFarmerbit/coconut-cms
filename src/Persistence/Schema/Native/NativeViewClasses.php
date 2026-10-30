<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;

/**
 * The class each type's entities load as in native code: its last native
 * class. A native type's own class; for an editor-created type, its nearest
 * native ancestor's, found up its stored parents. An `EditorEngine` held in
 * an `Engine` property loads as an `Engine`, its native view. A type with no
 * native ancestor has none.
 */
final class NativeViewClasses implements EntityClasses
{
    /** @var array<string, class-string|null> Each identifier's last native class, once looked up. */
    private array $known = [];

    public function __construct(
        private readonly NativeEntityRegistry $classes,
        private readonly SchemaLookup $schemas,
    ) {
    }

    /**
     * $identifier's last native class; null for a type with no native
     * ancestor, or one there's no schema for.
     * @return class-string|null
     */
    public function lastNativeClassOf(string $identifier): ?string {
        if (array_key_exists($identifier, $this->known)) {
            return $this->known[$identifier];
        }

        $class = null;
        for ($type = $identifier; $type !== null; $type = $this->schemas->has($type) ? $this->schemas->get($type)->parent : null) {
            if ($this->classes->has($type)) {
                $class = $this->classes->classOf($type);
                break;
            }
        }

        return $this->known[$identifier] = $class;
    }

    public function classOf(string $identifier): string {
        return $this->lastNativeClassOf($identifier) ?? throw new InvalidArgumentException(sprintf(
            '"%s" has no native class, its own or an ancestor\'s: it can only load as a DynamicEntity.',
            $identifier,
        ));
    }
}
