<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;

/**
 * Every converter and initializer the modules register, by identifier: what
 * an editor save picks one by, with its parameters. Built-ins are the core
 * module's. Builds one from a save's parameters, and writes one back.
 */
final class ConversionRegistry
{
    /** @var array<string, ConstructorShape> Converters, keyed by identifier. */
    private array $converters = [];

    /** @var array<string, ConstructorShape> Initializers, keyed by identifier. */
    private array $initializers = [];

    /** @var array<class-string, string> Every registered class's identifier. */
    private array $identifiers = [];

    public function __construct(
        private readonly EnumRegistry $enums,
    ) {
    }

    /**
     * Registers $class: a FieldConverter marked #[Converter], or a
     * FieldInitializer marked #[Initializer]. Throws LogicException for
     * anything else, or an identifier taken.
     * @param class-string $class
     */
    public function register(string $class): void {
        $reflection = new ReflectionClass($class);
        $converter = $reflection->getAttributes(Converter::class)[0] ?? null;
        $initializer = $reflection->getAttributes(Initializer::class)[0] ?? null;
        if ($converter !== null && $reflection->implementsInterface(FieldConverter::class)) {
            $identifier = $converter->newInstance()->identifier;
            $this->assertFree($identifier, $class);
            $this->converters[$identifier] = ConstructorShape::of($class);
        } elseif ($initializer !== null && $reflection->implementsInterface(FieldInitializer::class)) {
            $identifier = $initializer->newInstance()->identifier;
            $this->assertFree($identifier, $class);
            $this->initializers[$identifier] = ConstructorShape::of($class);
        } else {
            throw new LogicException(sprintf('%s is registered as a converter or initializer: it must be a FieldConverter marked #[Converter], or a FieldInitializer marked #[Initializer].', $class));
        }

        $this->identifiers[$class] = $identifier;
    }

    private function assertFree(string $identifier, string $class): void {
        if (isset($this->converters[$identifier]) || isset($this->initializers[$identifier])) {
            throw new LogicException(sprintf('%s takes "%s", which another converter or initializer has.', $class, $identifier));
        }
    }

    /**
     * The converter registered as $identifier, built from $parameters.
     * @param array<string, mixed> $parameters
     * @throws InvalidParameters When none is registered, or the parameters don't fit.
     */
    public function converter(string $identifier, array $parameters): FieldConverter {
        $shape = $this->converters[$identifier] ?? throw new InvalidParameters(null, sprintf('No converter is registered as "%s".', $identifier));

        return $shape->build($parameters, $this->enums);
    }

    /**
     * The initializer registered as $identifier, built from $parameters.
     * @param array<string, mixed> $parameters
     * @throws InvalidParameters When none is registered, or the parameters don't fit.
     */
    public function initializer(string $identifier, array $parameters): FieldInitializer {
        $shape = $this->initializers[$identifier] ?? throw new InvalidParameters(null, sprintf('No initializer is registered as "%s".', $identifier));

        return $shape->build($parameters, $this->enums);
    }

    /**
     * $built written back: its identifier, and its parameters the way
     * converter() or initializer() takes them. Null for one not registered,
     * a step's own in code.
     * @return array{string, array<string, mixed>}|null
     */
    public function describe(FieldConverter|FieldInitializer $built): ?array {
        $identifier = $this->identifiers[$built::class] ?? null;
        if ($identifier === null) {
            return null;
        }

        $shape = $this->converters[$identifier] ?? $this->initializers[$identifier];

        return [$identifier, $shape->parametersOf($built, $this->enums)];
    }
}
