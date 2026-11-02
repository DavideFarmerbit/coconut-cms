<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use BackedEnum;

/** One registered enum, as schema.values lists it: what a field may hold, and the values a default picks from. */
final readonly class EnumDescription
{
    /**
     * @param string $identifier What a field names it by.
     * @param list<string|int> $values Its cases' values, in order.
     */
    public function __construct(
        public string $identifier,
        public array $values,
    ) {
    }

    /** @param class-string<BackedEnum> $class */
    public static function of(string $identifier, string $class): self {
        return new self($identifier, array_map(static fn (BackedEnum $case): string|int => $case->value, $class::cases()));
    }
}
