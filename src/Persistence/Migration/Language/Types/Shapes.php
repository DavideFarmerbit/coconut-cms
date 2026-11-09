<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

/**
 * The types a step's paths go through, at one version: `old` reads the
 * shapes before the change, `new` the shapes after it. The checker asks it
 * for fields, subtypes and enum cases.
 */
interface Shapes
{
    /**
     * Every field of $type, its inherited ones included, by name; null when
     * there's no such type.
     * @return array<string, Type>|null
     */
    public function fieldsOf(string $type): ?array;

    /** Whether $type is $base, or extends it. */
    public function isA(string $type, string $base): bool;

    /**
     * Every case of $enum, by case name, with its backing value; null when
     * there's no such enum.
     * @return array<string, string|int>|null
     */
    public function casesOf(string $enum): ?array;
}
