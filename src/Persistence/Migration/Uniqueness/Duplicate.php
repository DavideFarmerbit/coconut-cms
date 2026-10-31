<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Uniqueness;

/**
 * Values a unique index would hold twice once a migration ran: the group,
 * the values, and the entities holding them. A migration finding one is
 * refused before it changes anything.
 */
final readonly class Duplicate
{
    /**
     * @param string $type The entity type declaring the group.
     * @param array<string, mixed> $values The group's values, keyed by column.
     * @param list<string> $entities The id of every entity holding them; for a Collection, its owner's.
     */
    public function __construct(
        public string $type,
        public array $values,
        public array $entities,
    ) {
    }

    /** How a refusal names it: `shop:product: sku "A-1", held by 0193…, 0194…`. */
    public function describe(): string {
        $values = [];
        foreach ($this->values as $column => $value) {
            $values[] = sprintf('%s %s', $column, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return sprintf('%s: %s, held by %s', $this->type, implode(', ', $values), implode(', ', $this->entities));
    }
}
