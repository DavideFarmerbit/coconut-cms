<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use InvalidArgumentException;

/**
 * Which native class each native identifier stands for: the way back from
 * what's stored to a class to build. Every native entity class must be
 * registered before anything of it is loaded. Two classes can't share an
 * identifier.
 */
final class NativeEntityRegistry
{
    /** @var array<string, class-string> Keyed by identifier. */
    private array $classes = [];

    /**
     * Registers $class under its identifier, and returns that identifier.
     * Registering the same class twice is harmless.
     * @param class-string $class
     */
    public function register(string $class): string {
        $identifier = NativeIdentifiers::of($class);

        $known = $this->classes[$identifier] ?? null;
        if ($known !== null && $known !== $class) {
            throw new InvalidArgumentException(sprintf(
                '%s and %s both have the identifier "%s": give one of them its own with #[Entity(identifier: ...)].',
                $known,
                $class,
                $identifier,
            ));
        }

        $this->classes[$identifier] = $class;

        return $identifier;
    }

    /**
     * The class registered under $identifier. Throws when there's none.
     * @return class-string
     */
    public function classOf(string $identifier): string {
        return $this->classes[$identifier] ?? throw new InvalidArgumentException(sprintf(
            'No native class is registered under "%s".',
            $identifier,
        ));
    }
}
