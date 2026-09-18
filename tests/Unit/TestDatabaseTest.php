<?php

namespace Peralta\AgentKit\Tests\Unit;

use InvalidArgumentException;
use Peralta\AgentKit\Tests\TestDatabase;
use PHPUnit\Framework\TestCase;

class TestDatabaseTest extends TestCase
{
    private const VARIABLES = [
        'AGENT_KIT_TEST_DB_CONNECTION',
        'AGENT_KIT_TEST_DB_HOST',
        'AGENT_KIT_TEST_DB_PORT',
        'AGENT_KIT_TEST_DB_DATABASE',
        'AGENT_KIT_TEST_DB_USERNAME',
        'AGENT_KIT_TEST_DB_PASSWORD',
    ];

    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->original[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function test_defaults_to_sqlite_in_memory(): void
    {
        $this->assertSame('sqlite', TestDatabase::driver());
        $this->assertFalse(TestDatabase::usesServer());
        $this->assertSame(
            ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            TestDatabase::connection(),
        );
    }

    public function test_builds_a_mysql_connection_with_utf8mb4_and_strict_mode(): void
    {
        putenv('AGENT_KIT_TEST_DB_CONNECTION=mysql');
        putenv('AGENT_KIT_TEST_DB_PORT=33061');
        putenv('AGENT_KIT_TEST_DB_PASSWORD=secret');

        $connection = TestDatabase::connection();

        $this->assertTrue(TestDatabase::usesServer());
        $this->assertSame('mysql', $connection['driver']);
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame('33061', $connection['port']);
        $this->assertSame('agent_kit_test', $connection['database']);
        $this->assertSame('root', $connection['username']);
        $this->assertSame('secret', $connection['password']);
        $this->assertSame('utf8mb4', $connection['charset']);
        $this->assertSame('utf8mb4_unicode_ci', $connection['collation']);
        $this->assertTrue($connection['strict']);
    }

    public function test_builds_a_postgres_connection_with_postgres_defaults(): void
    {
        putenv('AGENT_KIT_TEST_DB_CONNECTION=pgsql');

        $connection = TestDatabase::connection();

        $this->assertSame('pgsql', $connection['driver']);
        $this->assertSame('5432', $connection['port']);
        $this->assertSame('postgres', $connection['username']);
        $this->assertSame('', $connection['password']);
    }

    public function test_rejects_an_unknown_driver(): void
    {
        putenv('AGENT_KIT_TEST_DB_CONNECTION=oracle');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'oracle'");

        TestDatabase::connection();
    }
}
