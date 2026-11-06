<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration;

/**
 * For a test reading rows by hand: columns are generated, so its SQL names
 * fields, and sql() puts in where they're stored. The test holds $connection.
 */
trait ReadsStoredColumns
{
    /**
     * $sql with "{table.path}" replaced by that field's column,
     * "{#table.path}" by what its owned rows hold in owner_field, and
     * "{@table.path}" by the table holding its Collection's items.
     */
    private function sql(string $sql): string {
        return (new StoredColumns($this->connection))->sql($sql);
    }
}
