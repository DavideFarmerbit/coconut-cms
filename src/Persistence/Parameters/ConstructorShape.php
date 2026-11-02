<?php

namespace XyloIsCoding\CoconutCms\Persistence\Parameters;

use BackedEnum;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;

/**
 * A registered class's constructor, read once by reflection: how plain
 * parameters build one, and how one is written back as them. What a
 * converter, initializer or validator rule is configured by, in a save and
 * in a stored schema alike. Each parameter is a public property of the same
 * name, so what was built can be written back the same way.
 */
final class ConstructorShape
{
    /** @var array<class-string, self> Each class's, read once per request. */
    private static array $read = [];

    /**
     * @param class-string $class
     * @param list<ConstructorParameter> $parameters
     */
    private function __construct(
        public readonly string $class,
        public readonly array $parameters,
    ) {
    }

    /**
     * $class's constructor. Throws LogicException for a parameter of a type
     * plain data can't give, or one with no public property to write it back
     * from.
     * @param class-string $class
     */
    public static function of(string $class): self {
        if (isset(self::$read[$class])) {
            return self::$read[$class];
        }

        $reflection = new ReflectionClass($class);
        $parameters = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$reflection->hasProperty($parameter->getName()) || !$reflection->getProperty($parameter->getName())->isPublic()) {
                throw new LogicException(sprintf('%s: parameter $%s needs a public property of the same name, to be written back.', $class, $parameter->getName()));
            }

            $parameters[] = self::parameterOf($class, $parameter);
        }

        return self::$read[$class] = new self($class, $parameters);
    }

    private static function parameterOf(string $class, ReflectionParameter $parameter): ConstructorParameter {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new LogicException(sprintf('%s: parameter $%s needs one named type.', $class, $parameter->getName()));
        }

        $optional = $parameter->isDefaultValueAvailable();
        $default = $optional ? $parameter->getDefaultValue() : null;
        $named = $type->getName();
        if ($parameter->getAttributes(RegisteredEnum::class) !== []) {
            if ($named !== 'string') {
                throw new LogicException(sprintf('%s: parameter $%s is a #[RegisteredEnum]: it holds the enum class as a string.', $class, $parameter->getName()));
            }

            return new ConstructorParameter($parameter->getName(), ParameterType::RegisteredEnum, $type->allowsNull(), $optional, $default);
        }

        if ($named === FieldValidatorRule::class) {
            if (!$parameter->isVariadic()) {
                throw new LogicException(sprintf('%s: parameter $%s holds field rules: declare it variadic, FieldValidatorRule ...$%s.', $class, $parameter->getName(), $parameter->getName()));
            }

            return new ConstructorParameter($parameter->getName(), ParameterType::FieldRules, false, true, []);
        }

        $parameterType = match (true) {
            $named === 'string' => ParameterType::String,
            $named === 'int' => ParameterType::Int,
            $named === 'float' => ParameterType::Float,
            $named === 'bool' => ParameterType::Bool,
            $named === 'mixed' => ParameterType::Mixed,
            $named === ValueMapping::class => ParameterType::Map,
            is_subclass_of($named, BackedEnum::class) => ParameterType::Enum,
            default => throw new LogicException(sprintf('%s: parameter $%s takes %s, which plain parameters can\'t give: a string, int, float, bool, mixed, backed enum, ValueMapping or field rules only.', $class, $parameter->getName(), $named)),
        };

        return new ConstructorParameter($parameter->getName(), $parameterType, $type->allowsNull(), $optional, $default, $parameterType === ParameterType::Enum ? $named : null);
    }

    /**
     * One built from $data, keyed by parameter name.
     * @param array<string, mixed> $data
     * @throws InvalidParameters For a key missing, unknown, or of the wrong type.
     */
    public function build(array $data, ParameterRegistries $registries): object {
        $known = array_fill_keys(array_map(static fn (ConstructorParameter $parameter): string => $parameter->name, $this->parameters), true);
        foreach (array_keys($data) as $key) {
            if (!isset($known[$key])) {
                throw new InvalidParameters((string) $key, sprintf('"%s" is no parameter here.', $key));
            }
        }

        // In order, so a variadic last parameter spreads into its place.
        $arguments = [];
        foreach ($this->parameters as $parameter) {
            if (!array_key_exists($parameter->name, $data)) {
                if (!$parameter->optional) {
                    throw new InvalidParameters($parameter->name, sprintf('"%s" is missing.', $parameter->name));
                }

                $value = $parameter->default;
            } else {
                $value = self::valueOf($parameter, $data[$parameter->name], $registries);
            }

            if ($parameter->type === ParameterType::FieldRules) {
                array_push($arguments, ...$value);
            } else {
                $arguments[] = $value;
            }
        }

        return new $this->class(...$arguments);
    }

    /** $value read for $parameter. */
    private static function valueOf(ConstructorParameter $parameter, mixed $value, ParameterRegistries $registries): mixed {
        if ($value === null && $parameter->nullable) {
            return null;
        }

        $read = match ($parameter->type) {
            ParameterType::String => is_string($value) ? $value : null,
            ParameterType::Int => is_int($value) ? $value : null,
            ParameterType::Float => is_int($value) || is_float($value) ? (float) $value : null,
            ParameterType::Bool => is_bool($value) ? $value : null,
            ParameterType::Mixed => $value,
            ParameterType::Enum => is_string($value) || is_int($value) ? $parameter->enum::tryFrom($value) : null,
            ParameterType::RegisteredEnum => is_string($value) ? self::enumNamed($value, $registries) : null,
            ParameterType::FieldRules => is_array($value) && array_is_list($value) ? self::rulesFrom($parameter->name, $value, $registries) : null,
            ParameterType::Map => self::mappingFrom($parameter->name, $value),
        };
        if ($read === null && $parameter->type !== ParameterType::Mixed) {
            throw new InvalidParameters($parameter->name, sprintf('"%s" can\'t take %s.', $parameter->name, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }

        return $read;
    }

    /** The enum class registered as $identifier; null when none is. */
    private static function enumNamed(string $identifier, ParameterRegistries $registries): ?string {
        try {
            return $registries->enums->resolve(Identifier::fromString($identifier));
        } catch (InvalidArgumentException|RuntimeException) {
            return null;
        }
    }

    /**
     * Each rule in $entries built, a fault inside one named by where it is:
     * "rules.1.config.max".
     * @param list<mixed> $entries
     * @return list<FieldValidatorRule>
     */
    private static function rulesFrom(string $name, array $entries, ParameterRegistries $registries): array {
        $rules = [];
        foreach ($entries as $index => $entry) {
            try {
                $rules[] = $registries->fieldRules()->build($entry, $registries);
            } catch (InvalidParameters $refused) {
                throw new InvalidParameters($name . '.' . $index . ($refused->parameter === null ? '' : '.' . $refused->parameter), $refused->getMessage());
            }
        }

        return $rules;
    }

    /**
     * $value, a list of `{"from": value, "to": value}`, as a ValueMapping;
     * null when it isn't one. A key given twice is named by its place.
     */
    private static function mappingFrom(string $name, mixed $value): ?ValueMapping {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $pairs = [];
        foreach ($value as $index => $pair) {
            if (!is_array($pair) || (array_keys($pair) !== ['from', 'to'] && array_keys($pair) !== ['to', 'from'])) {
                throw new InvalidParameters($name . '.' . $index, sprintf('"%s.%d" is {"from": value, "to": value}.', $name, $index));
            }

            $pairs[] = [$pair['from'], $pair['to']];
        }

        try {
            return ValueMapping::of($pairs);
        } catch (InvalidArgumentException $refused) {
            throw new InvalidParameters($name, $refused->getMessage());
        }
    }

    /**
     * $built's parameters, the way build() takes them: an enum by its value,
     * a registered enum by its identifier, field rules each as
     * `{"rule": identifier, "config": parameters}`, a mapping as its pairs.
     * @return array<string, mixed>
     */
    public function parametersOf(object $built, ParameterRegistries $registries): array {
        $data = [];
        foreach ($this->parameters as $parameter) {
            $value = $built->{$parameter->name};
            $data[$parameter->name] = match (true) {
                $value === null => null,
                $parameter->type === ParameterType::Enum => $value->value,
                $parameter->type === ParameterType::RegisteredEnum => (string) $registries->enums->identifierOf($value),
                $parameter->type === ParameterType::FieldRules => array_map(static fn (FieldValidatorRule $rule): array => $registries->fieldRules()->entryOf($rule, $registries), $value),
                $parameter->type === ParameterType::Map => array_map(static fn (array $pair): array => ['from' => $pair[0], 'to' => $pair[1]], $value->pairs()),
                default => $value,
            };
        }

        return $data;
    }
}
