<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;
use XyloIsCoding\CoconutCms\Api\Output\ForHumans;
use XyloIsCoding\CoconutCms\Api\Output\TextTable;

/** One page of entities.query, and where the next one starts. */
final readonly class EntityPage implements OperationResult, ForHumans
{
    /**
     * @param list<EntityData> $items In order.
     * @param string|null $nextCursor What "after" takes for the next page; null on the last.
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }

    public function table(): TextTable {
        $rows = array_map(static function (EntityData $item): array {
            $scalars = array_filter($item->values, static fn (mixed $value): bool => is_scalar($value));
            $summary = implode(', ', array_map(
                static fn (string $name, mixed $value): string => $name . '=' . EntityData::text($value),
                array_keys($scalars),
                $scalars,
            ));

            return [$item->id, $item->type, $summary];
        }, $this->items);

        return new TextTable(['ID', 'TYPE', 'VALUES'], $rows, $this->nextCursor === null ? null : '--after=' . $this->nextCursor);
    }
}
