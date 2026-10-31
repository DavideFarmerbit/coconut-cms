<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use BackedEnum;

/** One constructor parameter of a registered converter or initializer, read by ConstructorShape. */
final readonly class ConstructorParameter
{
    /**
     * @param class-string<BackedEnum>|null $enum The enum an Enum parameter takes.
     * @param bool $optional Whether it has a default, so a save may leave it out.
     */
    public function __construct(
        public string $name,
        public ParameterType $type,
        public bool $nullable,
        public bool $optional,
        public mixed $default = null,
        public ?string $enum = null,
    ) {
    }
}
