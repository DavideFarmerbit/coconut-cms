<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Admin;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;
use XyloIsCoding\CoconutCms\Api\Output\ForHumans;
use XyloIsCoding\CoconutCms\Api\Output\TextTable;

/** Every module's admin code, modules in boot order. */
final readonly class AdminCodeList implements OperationResult, ForHumans
{
    /** @param list<AdminCodeEntry> $entries */
    public function __construct(
        public array $entries,
    ) {
    }

    public function table(): TextTable {
        return new TextTable(
            ['MODULE', 'KIND', 'PATH'],
            array_map(static fn (AdminCodeEntry $entry): array => [$entry->module, $entry->kind->value, $entry->path], $this->entries),
        );
    }
}
