<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use BackedEnum;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ConstructorParameter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ParameterType;

/**
 * One parameter a converter or initializer takes, as the editor shows it in
 * a form: what it takes, whether it may be left out, and the choices for an
 * enum.
 */
final readonly class ParameterDescription
{
    /**
     * @param string $type "string", "int", "float", "bool", "mixed" (a value
     *        in stored form), "enum" (one of $choices), or "registered_enum"
     *        (an enum's registered identifier).
     * @param mixed $default What it takes when left out; null when it can't be.
     * @param list<string|int>|null $choices An enum's values, in order.
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $optional,
        public mixed $default,
        public ?array $choices,
    ) {
    }

    public static function of(ConstructorParameter $parameter): self {
        $default = $parameter->default instanceof BackedEnum ? $parameter->default->value : $parameter->default;

        return new self(
            $parameter->name,
            match ($parameter->type) {
                ParameterType::String => 'string',
                ParameterType::Int => 'int',
                ParameterType::Float => 'float',
                ParameterType::Bool => 'bool',
                ParameterType::Mixed => 'mixed',
                ParameterType::Enum => 'enum',
                ParameterType::RegisteredEnum => 'registered_enum',
            },
            $parameter->nullable,
            $parameter->optional,
            $default,
            $parameter->enum === null ? null : array_map(static fn (BackedEnum $case): string|int => $case->value, $parameter->enum::cases()),
        );
    }
}
