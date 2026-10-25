<?php

namespace XyloIsCoding\CoconutCms\Persistence\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform as DoctrineSqlitePlatform;
use InvalidArgumentException;

/** Picks the DatabasePlatform a connection's database needs. */
final class Platforms
{
    /** Throws for a database persistence doesn't support. */
    public static function of(Connection $connection): DatabasePlatform {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            // MariaDB's Doctrine platform extends MySQL's.
            $platform instanceof AbstractMySQLPlatform => new MySqlPlatform(),
            $platform instanceof DoctrineSqlitePlatform => new SqlitePlatform(),
            default => throw new InvalidArgumentException(sprintf(
                '%s isn\'t supported: persistence runs on MySQL 8, MariaDB 10.6, and SQLite for tests, demos and small sites.',
                $platform::class,
            )),
        };
    }
}
