<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;

/**
 * Every converter and initializer the modules register, by identifier: what
 * an editor save picks one by, with its parameters. Built-ins are the core
 * module's. Builds one from a save's parameters, and writes one back.
 */
final class ConversionRegistry
{
    /** @var array<string, array{ConversionKind, ConstructorShape}> Every one registered, keyed by identifier, in order. */
    private array $registered = [];

    /** @var array<class-string, string> Every registered class's identifier. */
    private array $identifiers = [];

    public function __construct(
        private readonly EnumRegistry $enums,
    ) {
    }

    /**
     * Registers $class: a FieldConverter marked #[Converter], or a
     * FieldInitializer or LevelInitializer marked #[Initializer]. Throws
     * LogicException for anything else, or an identifier taken.
     * @param class-string $class
     */
    public function register(string $class): void {
        $reflection = new ReflectionClass($class);
        $converter = $reflection->getAttributes(Converter::class)[0] ?? null;
        $initializer = $reflection->getAttributes(Initializer::class)[0] ?? null;
        [$kind, $named] = match (true) {
            $converter !== null && $reflection->implementsInterface(FieldConverter::class) => [ConversionKind::Converter, $converter],
            $initializer !== null && $reflection->implementsInterface(FieldInitializer::class) => [ConversionKind::Initializer, $initializer],
            $initializer !== null && $reflection->implementsInterface(LevelInitializer::class) => [ConversionKind::LevelInitializer, $initializer],
            default => throw new LogicException(sprintf('%s is registered as a converter or initializer: it must be a FieldConverter marked #[Converter], or a FieldInitializer or LevelInitializer marked #[Initializer].', $class)),
        };

        $identifier = $named->newInstance()->identifier;
        if (isset($this->registered[$identifier])) {
            throw new LogicException(sprintf('%s takes "%s", which another converter or initializer has.', $class, $identifier));
        }

        $this->registered[$identifier] = [$kind, ConstructorShape::of($class)];
        $this->identifiers[$class] = $identifier;
    }

    /**
     * The converter registered as $identifier, built from $parameters.
     * @param array<string, mixed> $parameters
     * @throws InvalidParameters When none is registered, or the parameters don't fit.
     */
    public function converter(string $identifier, array $parameters): FieldConverter {
        return $this->build(ConversionKind::Converter, 'converter', $identifier, $parameters);
    }

    /**
     * The field initializer registered as $identifier, built from $parameters.
     * @param array<string, mixed> $parameters
     * @throws InvalidParameters When none is registered, or the parameters don't fit.
     */
    public function initializer(string $identifier, array $parameters): FieldInitializer {
        return $this->build(ConversionKind::Initializer, 'field initializer', $identifier, $parameters);
    }

    /**
     * The level initializer registered as $identifier, built from $parameters.
     * @param array<string, mixed> $parameters
     * @throws InvalidParameters When none is registered, or the parameters don't fit.
     */
    public function levelInitializer(string $identifier, array $parameters): LevelInitializer {
        return $this->build(ConversionKind::LevelInitializer, 'level initializer', $identifier, $parameters);
    }

    /** @param array<string, mixed> $parameters */
    private function build(ConversionKind $kind, string $what, string $identifier, array $parameters): object {
        [$registered, $shape] = $this->registered[$identifier] ?? [null, null];
        if ($registered !== $kind) {
            throw new InvalidParameters(null, sprintf('No %s is registered as "%s".', $what, $identifier));
        }

        return $shape->build($parameters, $this->enums);
    }

    /**
     * Every converter and initializer registered, in the order registered:
     * what the editor offers.
     * @return list<RegisteredConversion>
     */
    public function all(): array {
        $all = [];
        foreach ($this->registered as $identifier => [$kind, $shape]) {
            $class = $shape->class;
            $all[] = new RegisteredConversion(
                $identifier,
                $kind,
                $class,
                $kind === ConversionKind::Converter ? $class::from() : null,
                $kind === ConversionKind::LevelInitializer ? null : $class::to(),
                $shape,
            );
        }

        return $all;
    }

    /**
     * $built written back: its identifier, and its parameters the way
     * converter(), initializer() or levelInitializer() takes them. Null for
     * one not registered, a step's own in code.
     * @return array{string, array<string, mixed>}|null
     */
    public function describe(FieldConverter|FieldInitializer|LevelInitializer $built): ?array {
        $identifier = $this->identifiers[$built::class] ?? null;
        if ($identifier === null) {
            return null;
        }

        return [$identifier, $this->registered[$identifier][1]->parametersOf($built, $this->enums)];
    }
}
