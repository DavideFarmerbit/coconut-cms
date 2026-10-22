<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * The MySQL database every integration test runs against: its own, never the
 * site's, since the tests drop and recreate tables freely. Created the first
 * time a test connects.
 */
final class TestDatabase
{
    public const NAME = 'coconut_test';

    /** The local MySQL server, before choosing a database on it. */
    private const SERVER = [
        'driver' => 'pdo_mysql',
        'user' => 'root',
        'password' => 'root',
        'unix_socket' => '/Users/davidebosco/Library/Application Support/Local/run/PzVL9Ueo_/mysql/mysqld.sock',
    ];

    private static bool $created = false;

    /** A new connection to the test database. */
    public static function connect(): Connection {
        if (!self::$created) {
            $server = DriverManager::getConnection(self::SERVER);
            $server->executeStatement(sprintf('CREATE DATABASE IF NOT EXISTS %s', self::NAME));
            $server->close();
            self::$created = true;
        }

        return DriverManager::getConnection([...self::SERVER, 'dbname' => self::NAME]);
    }
}
