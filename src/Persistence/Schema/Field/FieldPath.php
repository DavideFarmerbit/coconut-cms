<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use InvalidArgumentException;
use Stringable;

/**
 * The path from one class down to one leaf value it owns: an Embed's fields
 * joined with "_", an OwningReference or a Collection item traversed with ".",
 * e.g. "chassis_engine_piston_diameter" or "engine.piston_diameter". Not a
 * column of that class: everything past a "." lives in another table. Distinct
 * from FieldName, which names one declared field on one entity's own level and
 * never carries a prefix. An unprefixed path is just the zero-prefix case of
 * this, not a FieldName in disguise.
 */
final readonly class FieldPath implements Stringable
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function of(string $value): self {
        if ($value === '') {
            throw new InvalidArgumentException('A field path cannot be empty.');
        }

        return new self($value);
    }

    public function __toString(): string {
        return $this->value;
    }
}
