<?php

namespace XyloIsCoding\CoconutCms\Api\Operation;

use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use XyloIsCoding\CoconutCms\Api\Input\InputShape;
use XyloIsCoding\CoconutCms\Core\Identifier;

/**
 * Every operation the API serves, by identifier. Registering one checks its
 * class: marked #[Operation], with a run() taking an OperationInput and the
 * OperationContext and returning an OperationResult, and an input every
 * transport can build. Two operations with one identifier are refused.
 */
final class OperationRegistry
{
    /** @var array<string, OperationDefinition> By identifier. */
    private array $definitions = [];

    /**
     * Registers $class, checked. Throws LogicException naming what's wrong.
     * @param class-string $class
     */
    public function register(string $class): OperationDefinition {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Operation::class);
        if ($attributes === []) {
            throw new LogicException(sprintf('%s is listed as an operation, but isn\'t marked #[Operation].', $class));
        }

        try {
            $identifier = Identifier::fromString($attributes[0]->newInstance()->identifier);
        } catch (InvalidArgumentException $invalid) {
            throw new LogicException(sprintf('%s: %s', $class, $invalid->getMessage()), 0, $invalid);
        }

        $known = $this->definitions[(string) $identifier] ?? null;
        if ($known !== null) {
            throw new LogicException(sprintf('Two operations are named "%s": %s and %s.', $identifier, $known->class, $class));
        }

        [$input, $result] = self::signatureOf($reflection);
        InputShape::of($input);

        return $this->definitions[(string) $identifier] = new OperationDefinition($identifier, $class, $input, $result);
    }

    /** The operation named $identifier; null when there's none. */
    public function find(string $identifier): ?OperationDefinition {
        return $this->definitions[$identifier] ?? null;
    }

    /** @return list<OperationDefinition> Every operation, sorted by identifier. */
    public function all(): array {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);

        return array_values($definitions);
    }

    /**
     * The input and result classes $class's run() declares.
     * @return array{class-string<OperationInput>, class-string<OperationResult>}
     */
    private static function signatureOf(ReflectionClass $class): array {
        $expected = sprintf('%s needs a public run(SomeInput $input, OperationContext $context): SomeResult', $class->getName());
        if (!$class->hasMethod('run') || !$class->getMethod('run')->isPublic() || $class->getMethod('run')->isStatic()) {
            throw new LogicException($expected . '.');
        }

        $constructor = $class->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new LogicException(sprintf('%s: an operation is built with no arguments.', $class->getName()));
        }

        $run = $class->getMethod('run');
        $parameters = $run->getParameters();
        $input = count($parameters) === 2 ? $parameters[0]->getType() : null;
        $context = count($parameters) === 2 ? $parameters[1]->getType() : null;
        $result = $run->getReturnType();

        $named = static fn (mixed $type): ?string => $type instanceof ReflectionNamedType && !$type->isBuiltin() && !$type->allowsNull() ? $type->getName() : null;
        if (
            !is_subclass_of($named($input) ?? '', OperationInput::class)
            || $named($context) !== OperationContext::class
            || !is_subclass_of($named($result) ?? '', OperationResult::class)
        ) {
            throw new LogicException(sprintf('%s, the input implementing %s and the result %s.', $expected, OperationInput::class, OperationResult::class));
        }

        return [$named($input), $named($result)];
    }
}
