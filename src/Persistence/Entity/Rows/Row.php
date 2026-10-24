<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Rows;

use Doctrine\DBAL\ParameterType;

/**
 * One row that should exist in one table: column values keyed by column name.
 */
final readonly class Row
{
    /**
     * @param array<string, mixed> $values Keyed by column name.
     * @param list<string> $referenceColumns The columns holding a Reference's id.
     */
    public function __construct(
        public string $table,
        public array $values,
        public array $referenceColumns = [],
    ) {
    }

    /**
     * This row with every Reference to one of $ids null: what the foreign key
     * would do, had the row existed when they were deleted.
     * @param list<string> $ids
     */
    public function withoutReferencesTo(array $ids): self {
        $values = $this->values;
        foreach ($this->referenceColumns as $column) {
            if (in_array($values[$column], $ids, true)) {
                $values[$column] = null;
            }
        }

        return new self($this->table, $values, $this->referenceColumns);
    }

    /**
     * How to bind each value, from its own PHP kind: PDO would otherwise send
     * false as an empty string, which no boolean column accepts.
     * @return array<string, ParameterType> Keyed by column name.
     */
    public function types(): array {
        return array_map(static fn (mixed $value): ParameterType => match (true) {
            $value === null => ParameterType::NULL,
            is_bool($value) => ParameterType::BOOLEAN,
            is_int($value) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        }, $this->values);
    }
}
