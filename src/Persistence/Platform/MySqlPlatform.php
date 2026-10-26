<?php

namespace XyloIsCoding\CoconutCms\Persistence\Platform;

use Closure;
use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;

/**
 * MySQL 8 and MariaDB 10.6: the production databases. Migrations hold a
 * named server lock; writes run read committed and lock their root row.
 */
final readonly class MySqlPlatform implements DatabasePlatform
{
    /** The named lock every migration holds while it runs. */
    private const MIGRATION_LOCK = 'coconut-cms-migration';

    public function prepare(Connection $connection): void {
    }

    public function lockMigrations(Connection $connection, int $timeoutSeconds): bool {
        return (int) $connection->fetchOne('SELECT GET_LOCK(?, ?)', [self::MIGRATION_LOCK, $timeoutSeconds]) === 1;
    }

    public function unlockMigrations(Connection $connection): void {
        $connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::MIGRATION_LOCK]);
    }

    /**
     * Read committed: locking an id not stored yet then locks no gap, so two
     * first saves never block each other, and every read after a lock sees
     * what the write holding it committed.
     */
    public function beforeWriteTransaction(Connection $connection): void {
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    public function lockEntity(Connection $connection, string $id): void {
        $connection->fetchOne(sprintf('SELECT id FROM %s WHERE id = ? FOR UPDATE', $connection->quoteSingleIdentifier(DatabaseSchema::ENTITIES)), [$id]);
    }

    public function nullSafeEquals(string $left, string $right): string {
        return sprintf('%s <=> %s', $left, $right);
    }

    /** MySQL changes tables in place; each statement commits on its own. */
    public function changingSchema(Connection $connection, Closure $changes): mixed {
        return $changes();
    }
}
