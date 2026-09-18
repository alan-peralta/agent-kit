<?php

namespace Peralta\AgentKit\Tests;

use InvalidArgumentException;

/**
 * The database the suite runs against: SQLite in memory by default, or a real server
 * described by the AGENT_KIT_TEST_DB_* environment variables.
 */
final class TestDatabase
{
    private const SERVER_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    public static function driver(): string
    {
        return getenv('AGENT_KIT_TEST_DB_CONNECTION') ?: 'sqlite';
    }

    public static function usesServer(): bool
    {
        return self::driver() !== 'sqlite';
    }

    /** @return array<string, mixed> */
    public static function connection(): array
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        if (! in_array($driver, self::SERVER_DRIVERS, true)) {
            throw new InvalidArgumentException(
                "Unsupported AGENT_KIT_TEST_DB_CONNECTION '{$driver}'. Use sqlite, mysql, mariadb or pgsql.",
            );
        }

        $postgres = $driver === 'pgsql';

        $connection = [
            'driver' => $driver,
            'host' => getenv('AGENT_KIT_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('AGENT_KIT_TEST_DB_PORT') ?: ($postgres ? '5432' : '3306'),
            'database' => getenv('AGENT_KIT_TEST_DB_DATABASE') ?: 'agent_kit_test',
            'username' => getenv('AGENT_KIT_TEST_DB_USERNAME') ?: ($postgres ? 'postgres' : 'root'),
            'password' => getenv('AGENT_KIT_TEST_DB_PASSWORD') ?: '',
            'prefix' => '',
        ];

        return $postgres
            ? $connection + ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer']
            : $connection + ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true, 'engine' => null];
    }
}
