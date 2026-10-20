<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use Countable;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * Fields whose values must be unique together: no two rows may hold the same
 * combination. Always at least one field, all of them the entity's own.
 * @implements IteratorAggregate<int, FieldName>
 */
final readonly class UniqueGroup implements IteratorAggregate, Countable
{
    /** @param list<FieldName> $fields */
    private function __construct(
        private array $fields,
    ) {
    }

    public static function of(FieldName ...$fields): self {
        if ($fields === []) {
            throw new InvalidArgumentException('A unique group needs at least one field.');
        }

        return new self(array_values($fields));
    }

    /** @return list<FieldName> */
    public function fields(): array {
        return $this->fields;
    }

    public function getIterator(): Iterator {
        yield from $this->fields;
    }

    public function count(): int {
        return count($this->fields);
    }
}
