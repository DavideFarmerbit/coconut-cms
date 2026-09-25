<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use LogicException;

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
