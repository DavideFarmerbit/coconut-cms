<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRowsReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;

/**
 * Reads a stored aggregate into AggregateValues, before it moves, with the
 * schemas still stored: every value as stored, each marked touched or not.
 * Content is dense, so what's stored is each value's effective one under the
 * old defaults. Must run before the migration stores its new schemas. Reads
 * what expand moved aside, "_old__" columns and tables, as what they were.
 */
final readonly class AggregateReader
{
    private EntityRowsReader $rows;

    /** @param list<string> $tablesMovedAside Tables expand moved aside, by their stored name. */
    public function __construct(
        Connection $connection,
        SchemaRegistry $registry,
        array $tablesMovedAside = [],
    ) {
        $this->rows = new EntityRowsReader($connection, new SchemaLookup($registry), readsMovedAside: true, tablesMovedAside: $tablesMovedAside);
    }

    /** Null when no entity has $id. */
    public function read(string $id): ?AggregateValues {
        $stored = $this->rows->read($id);
        if ($stored === null) {
            return null;
        }

        $values = [];
        $marks = [];
        foreach ($stored->values as $path => $value) {
            $values[(string) $path] = $value;
            $marks[(string) $path] = $stored->touched->has($path) ? ValueMark::Touched : ValueMark::Untouched;
        }

        return new AggregateValues($stored->identifier, $values, $marks, $stored->ownedIds);
    }
}
