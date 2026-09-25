<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use LogicException;
use XyloIsCoding\CoconutCms\Storage\Prototype\PrototypeRegistry;

/**
 * The developer-facing setup path for native classes: derives each class's table name
 * (an explicit #[TableName] attribute, or its own short class name), builds every
 * level's Table definition through SchemaBuilder::tablesForChain(), syncs them, and
 * returns an EntityManager built from that exact same resolved map.
 *
 * Replaces what used to be three independently hand-coordinated steps, building the
 * right Table definitions, syncing them, and separately listing class => table in
 * EntityManager's own map, with one call and one source of truth, so the two can never
 * drift apart.
 */
final class EntityRegistrar
{
    /**
     * @param class-string[] $classes every native entity class the app uses; listing a
     *   derived class alone is enough, its native ancestors are walked automatically
     */
    public static function register(Connection $connection, array $classes): EntityManager
    {
        $tables = self::resolveTables($classes);

        (new SchemaSynchronizer($connection))->syncAll(self::resolveDefinitions($classes, $tables));

        return new EntityManager($connection, $tables);
    }

    /**
     * Fixes up everything that goes stale after a native class is renamed in source:
     * renames its own table to match the new class's derived/declared name (a no-op if
     * it already matches, e.g. an explicit #[TableName] that didn't change), and
     * updates every prototypes.parent/prototype_fields.referenced_shape row that
     * mentioned the old class-string. $oldClass no longer exists as a real class by
     * the time this runs, so its current table name has to be supplied explicitly,
     * nothing durably records native table names anywhere; $newClass is the real,
     * currently-declared class this reflects on to resolve its own table name.
     *
     * @param class-string $newClass
     */
    public static function rename(Connection $connection, PrototypeRegistry $registry, string $oldClass, string $oldTableName, string $newClass): void
    {
        $newTableName = PrototypeShape::tableNameOfClass($newClass);

        if ($newTableName !== $oldTableName) {
            self::assertTableAvailable($connection, $newTableName);
            $connection->createSchemaManager()->renameTable($oldTableName, $newTableName);
        }

        $registry->renameReferences($oldClass, $newClass);
    }

    private static function assertTableAvailable(Connection $connection, string $tableName): void
    {
        if (in_array($tableName, $connection->createSchemaManager()->listTableNames(), true)) {
            throw new LogicException(sprintf('Table "%s" already exists.', $tableName));
        }
    }

    /**
     * @param class-string[] $classes
     * @return array<class-string, string>
     */
    private static function resolveTables(array $classes): array
    {
        $tables = [];
        foreach ($classes as $class) {
            foreach (PrototypeShape::chainOfClass($class) as $level) {
                $tables[$level] ??= PrototypeShape::tableNameOfClass($level);
            }
        }

        self::assertNoCollisions($tables);

        return $tables;
    }

    /** @param array<class-string, string> $tables */
    private static function assertNoCollisions(array $tables): void
    {
        $classesByTable = [];
        foreach ($tables as $class => $tableName) {
            $classesByTable[$tableName][] = $class;
        }

        foreach ($classesByTable as $tableName => $colliding) {
            if (count($colliding) > 1) {
                throw new LogicException(sprintf(
                    '%s all derive to table "%s", give at least one an explicit #[TableName].',
                    implode(' and ', $colliding),
                    $tableName,
                ));
            }
        }
    }

    /**
     * @param class-string[] $classes
     * @param array<class-string, string> $tables
     * @return Table[] deduplicated by name, so classes sharing a native ancestor don't
     *   each redefine that ancestor's table a second time
     */
    private static function resolveDefinitions(array $classes, array $tables): array
    {
        $definitions = [];
        foreach ($classes as $class) {
            foreach (SchemaBuilder::tablesForChain(PrototypeShape::chainOfClass($class), $tables) as $table) {
                $definitions[$table->getName()] = $table;
            }
        }

        return array_values($definitions);
    }
}
