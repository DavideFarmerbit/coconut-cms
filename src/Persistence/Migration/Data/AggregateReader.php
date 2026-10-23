<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRowsReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\ValueFlattener;

/**
 * Reads a stored aggregate into AggregateValues, before it moves: its touched
 * values with the schemas still stored, and every other value resolved from
 * the defaults still stored. Must run before the migration stores its new
 * schemas and values.
 */
final readonly class AggregateReader
{
    private SchemaLookup $schemas;
    private EntityRowsReader $rows;
    private FieldValueResolver $resolver;

    public function __construct(
        Connection $connection,
        SchemaRegistry $registry,
        SchemaFieldValueStore $values,
    ) {
        $this->schemas = new SchemaLookup($registry);
        $this->rows = new EntityRowsReader($connection, $this->schemas);
        $this->resolver = new FieldValueResolver($registry, $values);
    }

    /** Null when no entity has $id. */
    public function read(string $id): ?AggregateValues {
        $stored = $this->rows->read($id);
        if ($stored === null) {
            return null;
        }

        $tree = $this->resolver->resolveTree(Subject::ofInstance($stored->identifier, $stored->touched));
        $effective = (new ValueFlattener($this->schemas))->flatten($stored->identifier, $tree);

        $values = [];
        $marks = [];
        foreach ($effective as $path => $value) {
            $values[(string) $path] = $value;
            $marks[(string) $path] = $stored->touched->has($path) ? ValueMark::Touched : ValueMark::Untouched;
        }

        return new AggregateValues($stored->identifier, $values, $marks, $stored->ownedIds);
    }
}
