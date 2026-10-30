<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Types;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;
use XyloIsCoding\CoconutCms\Api\Output\ForHumans;
use XyloIsCoding\CoconutCms\Api\Output\TextTable;

/**
 * Every type the caller may read, sorted by identifier, and the schema
 * revision they're at: what an editor save built on them sends back.
 */
final readonly class TypeList implements OperationResult, ForHumans
{
    /** @param list<TypeDescription> $types */
    public function __construct(
        public array $types,
        public int $revision,
    ) {
    }

    public function table(): TextTable {
        return new TextTable(
            ['TYPE', 'PARENT', 'FIELDS', 'MAY'],
            array_map(static fn (TypeDescription $type): array => [
                $type->identifier,
                $type->parent ?? '',
                implode(', ', array_keys($type->fields)),
                implode(', ', array_keys(array_filter((array) $type->may))),
            ], $this->types),
        );
    }
}
