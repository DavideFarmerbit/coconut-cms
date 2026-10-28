<?php

namespace XyloIsCoding\CoconutCms\Api\Input;

use BackedEnum;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/**
 * What an OperationInput class takes: its constructor's parameters and their
 * types, read once by reflection. Reading it checks the class and every
 * input it nests, so a parameter of a type no input can hold fails at boot,
 * not on a call.
 */
final class InputShape
{
    /** @var array<class-string, self> Each class's shape, read once. */
    private static array $shapes = [];

    /** @param list<InputParameter> $parameters */
    private function __construct(
        public readonly string $class,
        public readonly array $parameters,
    ) {
    }

    /**
     * $class's shape. Throws LogicException when it isn't an OperationInput,
     * or a parameter's type isn't one an input can hold.
     * @param class-string $class
     */
    public static function of(string $class): self {
        if (isset(self::$shapes[$class])) {
            return self::$shapes[$class];
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->implementsInterface(OperationInput::class)) {
            throw new LogicException(sprintf('%s is used as an operation input, but doesn\'t implement %s.', $class, OperationInput::class));
        }

        $constructor = $reflection->getConstructor();
        $docs = $constructor === null ? [] : DocTypes::paramsOf($constructor);
        $parameters = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $parameters[] = new InputParameter(
                $parameter->getName(),
                self::typeOf($reflection, $parameter, $docs[$parameter->getName()] ?? null),
                $parameter->isDefaultValueAvailable(),
                $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            );
        }

        // Stored before nested inputs are read, so an input nesting itself ends.
        self::$shapes[$class] = $shape = new self($class, $parameters);
        try {
            foreach ($parameters as $parameter) {
                self::checkNested($parameter->type);
            }
        } catch (LogicException $invalid) {
            unset(self::$shapes[$class]);
            throw $invalid;
        }

        return $shape;
    }

    /** Reads the shape of every input $type nests, so each is checked. */
    private static function checkNested(InputType $type): void {
        if ($type->kind === InputKind::Input) {
            self::of($type->class);
        } elseif ($type->kind === InputKind::List) {
            self::checkNested($type->item);
        }
    }

    /** $parameter's type, its list item's from $doc, its @param type. */
    private static function typeOf(ReflectionClass $class, ReflectionParameter $parameter, ?string $doc): InputType {
        $type = $parameter->getType();
        $where = sprintf('%s::__construct($%s)', $class->getName(), $parameter->getName());
        if (!$type instanceof ReflectionNamedType || $parameter->isVariadic()) {
            throw new LogicException(sprintf('%s: an input parameter has one named type, not a union, and isn\'t variadic.', $where));
        }

        if ($type->getName() !== 'array') {
            return self::named($class, $type->getName(), $type->allowsNull(), $where);
        }

        $item = $doc === null ? null : DocTypes::listItemOf($doc);
        if ($item === null) {
            throw new LogicException(sprintf('%s: an array parameter needs a @param naming its items, such as list<string>.', $where));
        }

        $itemName = ltrim($item, '?');
        $itemType = self::named($class, in_array($itemName, ['string', 'int', 'float', 'bool', 'mixed'], true) ? $itemName : DocTypes::resolveClass($class, $itemName), $item !== $itemName, $where);

        return new InputType(InputKind::List, $type->allowsNull(), item: $itemType);
    }

    /** The type named $name: a builtin, a backed enum or an input class. */
    private static function named(ReflectionClass $class, string $name, bool $nullable, string $where): InputType {
        $kind = match ($name) {
            'string' => InputKind::String,
            'int' => InputKind::Int,
            'float' => InputKind::Float,
            'bool' => InputKind::Bool,
            'mixed' => InputKind::Mixed,
            default => null,
        };
        if ($kind !== null) {
            return new InputType($kind, $nullable || $kind === InputKind::Mixed);
        }

        if (is_subclass_of($name, BackedEnum::class)) {
            return new InputType(InputKind::Enum, $nullable, $name);
        }

        if (is_subclass_of($name, OperationInput::class)) {
            return new InputType(InputKind::Input, $nullable, $name);
        }

        throw new LogicException(sprintf('%s: an input holds strings, ints, floats, bools, backed enums, other inputs and lists of them, not %s.', $where, $name));
    }
}
