<?php

namespace XyloIsCoding\CoconutCms\Persistence\Platform;

use Closure;
use Doctrine\DBAL\Connection;
use RuntimeException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;

/**
 * SQLite: for tests, demos and small sites, never production with several
 * editors at once. It lets one writer in at a time, so a write locks the whole
 * database rather than a row. It has no named locks, so a migration locks a
 * file next to the database. It changes most of a table by rebuilding it, so
 * schema changes run with foreign keys off.
 *
 * A Decimal keeps 15 significant digits here: SQLite stores numbers as
 * floating point.
 */
final class SqlitePlatform implements DatabasePlatform
{
    /** How long a write waits for another to finish, in milliseconds. */
    private const BUSY_TIMEOUT = 10_000;

    /**
     * The open migration lock files, by path: a lock is held for as long as
     * its file stays open, across instances.
     * @var array<string, resource>
     */
    private static array $locks = [];

    /** Foreign keys are off by default in SQLite, so every delete rule would silently do nothing. */
    public function prepare(Connection $connection): void {
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement(sprintf('PRAGMA busy_timeout = %d', self::BUSY_TIMEOUT));
    }

    public function lockMigrations(Connection $connection, int $timeoutSeconds): bool {
        $path = self::lockPathOf($connection);
        if ($path === null) {
            return true;
        }

        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Can\'t open the migration lock file "%s".', $path));
        }

        $deadline = microtime(true) + $timeoutSeconds;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                return false;
            }

            usleep(100_000);
        }

        self::$locks[$path] = $handle;

        return true;
    }

    public function unlockMigrations(Connection $connection): void {
        $path = self::lockPathOf($connection);
        if ($path === null || !isset(self::$locks[$path])) {
            return;
        }

        flock(self::$locks[$path], LOCK_UN);
        fclose(self::$locks[$path]);
        unset(self::$locks[$path]);
    }

    /** SQLite only has serializable transactions. */
    public function beforeWriteTransaction(Connection $connection): void {
    }

    /**
     * SQLite has no row locks: a write statement takes the database's write
     * lock instead, until the transaction ends. Taken now, a second write
     * waits here, instead of failing once it tries to write. It sets a column
     * nothing points at, to itself: touching the id would check every
     * foreign key pointing at it.
     */
    public function lockEntity(Connection $connection, string $id): void {
        $connection->executeStatement(sprintf('UPDATE %s SET position = position WHERE id = ?', $connection->quoteSingleIdentifier(DatabaseSchema::ENTITIES)), [$id]);
    }

    /**
     * SQLite rebuilds a table for most changes: dropped and created again,
     * which with foreign keys on would delete or block what points at it. So
     * they're off while $changes run, and every foreign key is checked after.
     */
    public function changingSchema(Connection $connection, Closure $changes): mixed {
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        try {
            $result = $changes();
        } finally {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }

        $broken = $connection->fetchAllAssociative('PRAGMA foreign_key_check');
        if ($broken !== []) {
            throw new RuntimeException(sprintf('A schema change left %d row(s) pointing at nothing, first in "%s".', count($broken), $broken[0]['table']));
        }

        return $result;
    }

    /** The lock file next to the database's own, null for one in memory: only one connection ever sees it. */
    private static function lockPathOf(Connection $connection): ?string {
        $path = $connection->getParams()['path'] ?? null;

        return $path === null || $path === ':memory:' ? null : $path . '.migration-lock';
    }
}
