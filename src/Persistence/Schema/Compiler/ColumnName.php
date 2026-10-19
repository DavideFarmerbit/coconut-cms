<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use InvalidArgumentException;
use Stringable;

/**
 * The name a field actually ends up under on its real table, after
 * ColumnFlattener's own Embed-chain prefixing. Distinct from FieldName: a
 * FieldName never carries a prefix, since it names one declared field on one
 * entity's own level; a ColumnName can, since flattening a field three Embeds
 * deep produces something like "chassis_engine_piston_diameter". An
 * unprefixed column is just the zero-prefix case of this, not a FieldName in
 * disguise.
 */
final readonly class ColumnName implements Stringable
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function of(string $value): self {
        if ($value === '') {
            throw new InvalidArgumentException('A column name cannot be empty.');
        }

        return new self($value);
    }

    public function __toString(): string {
        return $this->value;
    }
}
