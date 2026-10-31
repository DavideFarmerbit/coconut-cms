<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use BackedEnum;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;

/**
 * A registered converter's or initializer's constructor, read once by
 * reflection: how a save's parameters build one, and how one is written
 * back. Each parameter is a public property of the same name, so what was
 * built can be written back the same way.
 */
final readonly class ConstructorShape
{
    /**
     * @param class-string $class
     * @param list<ConstructorParameter> $parameters
     */
    private function __construct(
        public string $class,
        public array $parameters,
    ) {
    }

    /**
     * $class's constructor. Throws LogicException at boot for a parameter of
     * a type a save can't give, or one with no public property to write it
     * back from.
     * @param class-string $class
     */
    public static function of(string $class): self {
        $reflection = new ReflectionClass($class);
        $parameters = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$reflection->hasProperty($parameter->getName()) || !$reflection->getProperty($parameter->getName())->isPublic()) {
                throw new LogicException(sprintf('%s: parameter $%s needs a public property of the same name, to be written back.', $class, $parameter->getName()));
            }

            $parameters[] = self::parameterOf($class, $parameter);
        }

        return new self($class, $parameters);
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

        $parameterType = match (true) {
            $named === 'string' => ParameterType::String,
            $named === 'int' => ParameterType::Int,
            $named === 'float' => ParameterType::Float,
            $named === 'bool' => ParameterType::Bool,
            $named === 'mixed' => ParameterType::Mixed,
            is_subclass_of($named, BackedEnum::class) => ParameterType::Enum,
            default => throw new LogicException(sprintf('%s: parameter $%s takes %s, which a save can\'t give: a string, int, float, bool, mixed or backed enum only.', $class, $parameter->getName(), $named)),
        };

        return new ConstructorParameter($parameter->getName(), $parameterType, $type->allowsNull(), $optional, $default, $parameterType === ParameterType::Enum ? $named : null);
    }

    /**
     * One built from $data, keyed by parameter name.
     * @param array<string, mixed> $data
     * @throws InvalidParameters For a key missing, unknown, or of the wrong type.
     */
    public function build(array $data, EnumRegistry $enums): object {
        $known = array_fill_keys(array_map(static fn (ConstructorParameter $parameter): string => $parameter->name, $this->parameters), true);
        foreach (array_keys($data) as $key) {
            if (!isset($known[$key])) {
                throw new InvalidParameters((string) $key, sprintf('"%s" is no parameter here.', $key));
            }
        }

        $arguments = [];
        foreach ($this->parameters as $parameter) {
            if (!array_key_exists($parameter->name, $data)) {
                if (!$parameter->optional) {
                    throw new InvalidParameters($parameter->name, sprintf('"%s" is missing.', $parameter->name));
                }

                $arguments[$parameter->name] = $parameter->default;
                continue;
            }

            $arguments[$parameter->name] = self::valueOf($parameter, $data[$parameter->name], $enums);
        }

        return new $this->class(...$arguments);
    }

    /** $value read for $parameter. */
    private static function valueOf(ConstructorParameter $parameter, mixed $value, EnumRegistry $enums): mixed {
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
            ParameterType::RegisteredEnum => is_string($value) ? self::enumNamed($value, $enums) : null,
        };
        if ($read === null && $parameter->type !== ParameterType::Mixed) {
            throw new InvalidParameters($parameter->name, sprintf('"%s" can\'t take %s.', $parameter->name, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }

        return $read;
    }

    /** The enum class registered as $identifier; null when none is. */
    private static function enumNamed(string $identifier, EnumRegistry $enums): ?string {
        try {
            return $enums->resolve(Identifier::fromString($identifier));
        } catch (InvalidArgumentException|RuntimeException) {
            return null;
        }
    }

    /**
     * $built's parameters, the way build() takes them: an enum by its value,
     * a registered enum by its identifier.
     * @return array<string, mixed>
     */
    public function parametersOf(object $built, EnumRegistry $enums): array {
        $data = [];
        foreach ($this->parameters as $parameter) {
            $value = $built->{$parameter->name};
            $data[$parameter->name] = match (true) {
                $value === null => null,
                $parameter->type === ParameterType::Enum => $value->value,
                $parameter->type === ParameterType::RegisteredEnum => (string) $enums->identifierOf($value),
                default => $value,
            };
        }

        return $data;
    }
}
