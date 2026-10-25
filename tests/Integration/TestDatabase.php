<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use XyloIsCoding\CoconutCms\Persistence\Platform\Platforms;

/**
 * The database every integration test runs against: its own, never the
 * site's, since the tests drop and recreate tables freely. MySQL unless the
 * COCONUT_TEST_DB environment variable says "sqlite":
 *
 *     COCONUT_TEST_DB=sqlite vendor/bin/phpunit --testsuite integration
 */
final class TestDatabase
{
    public const NAME = 'coconut_test';

    /** The local MySQL server, before choosing a database on it. */
    private const MYSQL_SERVER = [
        'driver' => 'pdo_mysql',
        'user' => 'root',
        'password' => 'root',
        'unix_socket' => '/Users/davidebosco/Library/Application Support/Local/run/PzVL9Ueo_/mysql/mysqld.sock',
    ];

    private static bool $created = false;

    /** A new connection to the test database, readied for persistence. */
    public static function connect(): Connection {
        $connection = getenv('COCONUT_TEST_DB') === 'sqlite' ? self::sqlite() : self::mysql();
        Platforms::of($connection)->prepare($connection);

        return $connection;
    }

    /** Drops every table, whatever points at what. */
    public static function dropEverything(Connection $connection): void {
        $isSqlite = $connection->getDatabasePlatform() instanceof SQLitePlatform;
        $connection->executeStatement($isSqlite ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $connection->executeStatement(sprintf('DROP TABLE %s', $connection->quoteSingleIdentifier($table)));
        }
        $connection->executeStatement($isSqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function mysql(): Connection {
        if (!self::$created) {
            $server = DriverManager::getConnection(self::MYSQL_SERVER);
            $server->executeStatement(sprintf('CREATE DATABASE IF NOT EXISTS %s', self::NAME));
            $server->close();
            self::$created = true;
        }

        return DriverManager::getConnection([...self::MYSQL_SERVER, 'dbname' => self::NAME]);
    }

    /** A file, not memory: some tests open a second connection to the same database. */
    private static function sqlite(): Connection {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => sys_get_temp_dir() . '/' . self::NAME . '.sqlite']);
    }
}
