<?php

namespace XyloIsCoding\CoconutCms\Persistence\Platform;

use Closure;
use Doctrine\DBAL\Connection;

/**
 * Everything persistence does that the supported databases do differently:
 * MySQL and MariaDB in production, SQLite for tests, demos and small sites.
 * Every other statement is plain SQL all of them run, with every name quoted
 * by Doctrine. Get one with Platforms::of().
 */
interface DatabasePlatform
{
    /** Readies $connection, once, before anything else runs on it. */
    public function prepare(Connection $connection): void;

    /**
     * Takes the lock one migration holds at a time, waiting up to
     * $timeoutSeconds for one already running: false when it's still held.
     */
    public function lockMigrations(Connection $connection, int $timeoutSeconds): bool;

    /** Releases what lockMigrations() took. */
    public function unlockMigrations(Connection $connection): void;

    /** Runs right before the outermost transaction a write runs in. */
    public function beforeWriteTransaction(Connection $connection): void;

    /**
     * Inside a write transaction: locks entity $id's "entities" row until the
     * transaction ends, so two writes of one aggregate never run at once. An id
     * not stored yet locks nothing else.
     */
    public function lockEntity(Connection $connection, string $id): void;

    /**
     * Runs $changes, schema changes Doctrine generated, the way this database
     * needs them run.
     * @template T
     * @param Closure(): T $changes
     * @return T
     */
    public function changingSchema(Connection $connection, Closure $changes): mixed;
}
