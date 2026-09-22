<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;

/**
 * Applies a desired Table definition to a real database: creates it if it doesn't
 * exist yet, alters it to match otherwise. Diffs against the live schema rather than
 * assuming state, so it's safe to call every time, not just on first setup.
 *
 * Standard Doctrine migrations workflow (diff, generate DDL, apply) collapsed into one
 * call, appropriate for the safe, additive-only changes this system allows at runtime.
 * A real deploy-time migration review is a separate concern, not this class's job.
 */
final class SchemaSynchronizer
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function sync(Table $table): void
    {
        $this->syncAll([$table]);
    }

    /**
     * Syncs every table in one diff/apply pass, not one at a time, so a join table
     * referencing a sibling table that's also being created in this same call sees it
     * already present in the desired schema.
     *
     * @param Table[] $tables
     */
    public function syncAll(array $tables): void
    {
        $manager = $this->connection->createSchemaManager();
        $current = $manager->introspectSchema();

        $names = array_map(static fn (Table $table): string => $table->getName(), $tables);
        $untouched = array_filter($current->getTables(), static fn (Table $existing): bool => !in_array($existing->getName(), $names, true));

        $desired = new Schema([...$untouched, ...$tables], schemaConfig: $manager->createSchemaConfig());

        $manager->alterSchema($manager->createComparator()->compareSchemas($current, $desired));
    }
}
