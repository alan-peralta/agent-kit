# MySQL Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run Agent Kit's relational layer on SQLite, MySQL, MariaDB and PostgreSQL with proof in CI, and add a portable `database` knowledge store that works on any of them.

**Architecture:** Package migrations split into one directory and one publish tag per concern (core, pgvector, database store). The test case reads an optional server connection from `AGENT_KIT_TEST_DB_*`, database tests carry `#[Group('database')]`, and a GitHub Actions matrix runs that group on real servers. `DatabaseVectorStore` implements the existing `KnowledgeStore` contract with base64 float32 embeddings in a plain table and a dot-product ranking in PHP.

**Tech Stack:** PHP 8.2+, Laravel 10–12 (Illuminate Database), Orchestra Testbench, PHPUnit 10/11 attributes, GitHub Actions service containers, Docker for local servers.

**Spec:** `docs/superpowers/specs/2026-09-18-mysql-compatibility-design.md`

## Global Constraints

- Source code must run on PHP 8.2 and Laravel 10, 11 and 12. No framework API newer than Laravel 10 in `src/` or `database/`.
- Code, code comments, commit messages and English docs in English. `README.md`, `SETUP.md`, `CONTRIBUTING.md`, `ARCHITECTURE.md`, `CHANGELOG.md`, `config/agent-kit.php` comments and `.env.example` are pt-BR with full diacritics (never "nao", always "não").
- Every commit message ends with a blank line and `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
- Run tests with `vendor/bin/phpunit --colors=never --no-coverage …`. Test runs dirty the tracked `.phpunit.cache/test-results`; run `git checkout -- .phpunit.cache/test-results` before every `git add`/`git commit`, and never commit that file.
- Never run `vendor/bin/testbench` directly (it copies `.env.example` into the Testbench skeleton and pollutes later tests).
- Publish tags, exactly: `agent-kit-migrations`, `agent-kit-pgvector-migrations`, `agent-kit-database-store-migrations`.
- Test database variables, exactly: `AGENT_KIT_TEST_DB_CONNECTION`, `AGENT_KIT_TEST_DB_HOST`, `AGENT_KIT_TEST_DB_PORT`, `AGENT_KIT_TEST_DB_DATABASE`, `AGENT_KIT_TEST_DB_USERNAME`, `AGENT_KIT_TEST_DB_PASSWORD`.
- Local database servers for verification are already running (do not start or stop them): MySQL 8.4 on port `33061`, MariaDB 11.8 on `33062`, PostgreSQL 17 + pgvector on `54329`; password `agentkit`, database `agent_kit_test` on all three. The three verification commands used throughout this plan are:

```bash
AGENT_KIT_TEST_DB_CONNECTION=mysql   AGENT_KIT_TEST_DB_PORT=33061 AGENT_KIT_TEST_DB_PASSWORD=agentkit vendor/bin/phpunit --colors=never --no-coverage --group database
AGENT_KIT_TEST_DB_CONNECTION=mariadb AGENT_KIT_TEST_DB_PORT=33062 AGENT_KIT_TEST_DB_PASSWORD=agentkit vendor/bin/phpunit --colors=never --no-coverage --group database
AGENT_KIT_TEST_DB_CONNECTION=pgsql   AGENT_KIT_TEST_DB_PORT=54329 AGENT_KIT_TEST_DB_PASSWORD=agentkit vendor/bin/phpunit --colors=never --no-coverage --group database
```

## File Map

| File | Responsibility |
|---|---|
| `tests/TestDatabase.php` (new) | Builds the `testing` connection from `AGENT_KIT_TEST_DB_*` |
| `tests/PackageMigrations.php` (new) | Loads package migration instances from a directory |
| `tests/TestCase.php` | Uses `TestDatabase`; drops tables after each test on a server |
| `database/knowledge/pgvector/…_create_knowledge_chunks_table.php` (moved) | pgvector table, own tag |
| `database/knowledge/database/2026_09_18_000004_create_database_store_knowledge_chunks_table.php` (new) | Portable knowledge table |
| `src/AgentKitServiceProvider.php` | Three publish tags; `database` store binding |
| `config/agent-kit.php` | `knowledge.stores.database` entry |
| `src/Knowledge/Stores/DatabaseVectorStore.php` (new) | The portable store |
| `.github/workflows/tests.yml` (new) | CI: SQLite suite + database matrix |
| `tests/Feature/Database/*` (new) | Publishing, migrations, pgvector, docs tests |
| Docs | README, SETUP, ARCHITECTURE, CONTRIBUTING, CHANGELOG, `.env.example`, `composer.json` keywords |

---

### Task 1: Configurable test database and the `database` group

**Files:**
- Create: `tests/TestDatabase.php`
- Create: `tests/Unit/TestDatabaseTest.php`
- Create: `tests/Feature/Database/TestConnectionTest.php`
- Modify: `tests/TestCase.php`
- Modify (add the group attribute only): `tests/Unit/Conversation/DatabaseStoreTest.php`, `tests/Unit/Conversation/HybridStoreTest.php`, `tests/Unit/Models/AgentMetricTest.php`, `tests/Unit/Listeners/PersistMetricsListenerTest.php`, `tests/Feature/AnalyticsIntegrationTest.php`, `tests/Feature/AnalyticsListenerRegistrationTest.php`
- Modify: `CONTRIBUTING.md`

**Interfaces:**
- Produces: `Peralta\AgentKit\Tests\TestDatabase` with `public static function driver(): string`, `public static function usesServer(): bool`, `public static function connection(): array`. The `database` PHPUnit group.

- [ ] **Step 1: Write the failing unit test for `TestDatabase`**

Create `tests/Unit/TestDatabaseTest.php`:

```php
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
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Unit/TestDatabaseTest.php`
Expected: errors with `Class "Peralta\AgentKit\Tests\TestDatabase" not found`.

- [ ] **Step 3: Implement `TestDatabase`**

Create `tests/TestDatabase.php`:

```php
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
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Unit/TestDatabaseTest.php`
Expected: `OK (4 tests, …)`.

- [ ] **Step 5: Write the failing connection test**

This test proves a server run really uses the server (a misconfigured suite silently falling back to SQLite is the failure it guards against). Create `tests/Feature/Database/TestConnectionTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Tests\TestCase;
use Peralta\AgentKit\Tests\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class TestConnectionTest extends TestCase
{
    public function test_the_default_connection_uses_the_configured_driver(): void
    {
        $this->assertSame('testing', DB::getDefaultConnection());
        $this->assertSame(TestDatabase::driver(), DB::connection()->getDriverName());
    }
}
```

Run: `AGENT_KIT_TEST_DB_CONNECTION=mysql AGENT_KIT_TEST_DB_PORT=33061 AGENT_KIT_TEST_DB_PASSWORD=agentkit vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Database/TestConnectionTest.php`
Expected: FAIL, `'mysql'` expected but `'sqlite'` given (the test case still hardcodes SQLite).

- [ ] **Step 6: Make `TestCase` use `TestDatabase` and clean server databases**

Replace `tests/TestCase.php` with:

```php
<?php

namespace Peralta\AgentKit\Tests;

use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Peralta\AgentKit\AgentKitServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AgentKitServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', TestDatabase::connection());
    }

    protected function tearDown(): void
    {
        // A server database outlives the test, unlike SQLite in memory: drop what the test created.
        if (TestDatabase::usesServer() && $this->app !== null) {
            Schema::dropAllTables();
        }

        parent::tearDown();
    }
}
```

- [ ] **Step 7: Tag the existing database tests**

In each of these six files add `use PHPUnit\Framework\Attributes\Group;` to the imports (keep imports alphabetical) and the line `#[Group('database')]` directly above the `class` line. Change nothing else.

- `tests/Unit/Conversation/DatabaseStoreTest.php`
- `tests/Unit/Conversation/HybridStoreTest.php`
- `tests/Unit/Models/AgentMetricTest.php`
- `tests/Unit/Listeners/PersistMetricsListenerTest.php`
- `tests/Feature/AnalyticsIntegrationTest.php`
- `tests/Feature/AnalyticsListenerRegistrationTest.php`

Example of the result for the first file's header:

```php
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class DatabaseStoreTest extends TestCase
```

- [ ] **Step 8: Verify on SQLite and on the three servers**

Run: `vendor/bin/phpunit --colors=never --no-coverage --group database`
Expected: `OK`, 20 tests (19 existing database tests + `TestConnectionTest`).

Run the three server commands from Global Constraints.
Expected: `OK` with the same test count on MySQL, MariaDB and PostgreSQL.

Run: `vendor/bin/phpunit --colors=never --no-coverage`
Expected: the whole suite passes on SQLite (597 tests: 592 before this task, plus 5 new). `StdioServerCommandTest` spawns a subprocess and has failed intermittently before this work with exit code 255; if it fails, re-run it alone (`vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Mcp/StdioServerCommandTest.php`) and report it, do not change it.

- [ ] **Step 9: Document running against a server in `CONTRIBUTING.md`**

In the "Requisitos" list, replace the line `- Extensão \`pdo_sqlite\` habilitada (usada na suíte de testes)` with:

```markdown
- Extensão `pdo_sqlite` habilitada (usada na suíte de testes)
- Opcional: `pdo_mysql` e `pdo_pgsql`, para rodar os testes de banco contra MySQL, MariaDB ou PostgreSQL
```

Directly after the `vendor/bin/phpunit --coverage-text` code block (end of "Rodando os testes"), add:

````markdown
### Contra MySQL, MariaDB ou PostgreSQL

Por padrão a suíte usa SQLite em memória. Os testes que tocam o banco pertencem ao
grupo `database` e também rodam contra um servidor real, escolhido por variáveis de
ambiente:

| Variável | Padrão |
|---|---|
| `AGENT_KIT_TEST_DB_CONNECTION` | `sqlite` (ou `mysql`, `mariadb`, `pgsql`) |
| `AGENT_KIT_TEST_DB_HOST` | `127.0.0.1` |
| `AGENT_KIT_TEST_DB_PORT` | `3306` ou `5432`, conforme o driver |
| `AGENT_KIT_TEST_DB_DATABASE` | `agent_kit_test` |
| `AGENT_KIT_TEST_DB_USERNAME` | `root` (MySQL e MariaDB) ou `postgres` |
| `AGENT_KIT_TEST_DB_PASSWORD` | vazio |

Exemplo com Docker:

```bash
docker run -d --rm --name agent-kit-mysql -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=agent_kit_test -p 33061:3306 mysql:8.4
docker run -d --rm --name agent-kit-mariadb -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=agent_kit_test -p 33062:3306 mariadb:11.8
docker run -d --rm --name agent-kit-pgsql -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=agent_kit_test -p 54329:5432 pgvector/pgvector:pg17

AGENT_KIT_TEST_DB_CONNECTION=mysql AGENT_KIT_TEST_DB_PORT=33061 AGENT_KIT_TEST_DB_PASSWORD=secret vendor/bin/phpunit --group database
```

Com um servidor configurado, o `TestCase` apaga todas as tabelas do banco ao fim de
cada teste. Use um banco dedicado aos testes, nunca o da sua aplicação. O driver
`mariadb` exige Laravel 11 ou superior.
````

In the "Testes" list, after the bullet that starts with `- Prefira testar comportamento real`, add:

```markdown
- Marque com `#[Group('database')]` todo teste que toca o banco, para que ele rode também contra MySQL, MariaDB e PostgreSQL.
```

- [ ] **Step 10: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add tests/TestDatabase.php tests/TestCase.php tests/Unit/TestDatabaseTest.php tests/Feature/Database/TestConnectionTest.php \
  tests/Unit/Conversation/DatabaseStoreTest.php tests/Unit/Conversation/HybridStoreTest.php tests/Unit/Models/AgentMetricTest.php \
  tests/Unit/Listeners/PersistMetricsListenerTest.php tests/Feature/AnalyticsIntegrationTest.php tests/Feature/AnalyticsListenerRegistrationTest.php \
  CONTRIBUTING.md
git commit -m "test: run the database group against MySQL, MariaDB or PostgreSQL

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: The pgvector migration gets its own publish tag

**Files:**
- Move: `database/migrations/2026_05_05_000002_create_knowledge_chunks_table.php` → `database/knowledge/pgvector/2026_05_05_000002_create_knowledge_chunks_table.php` (content unchanged)
- Modify: `src/AgentKitServiceProvider.php` (the `boot()` publishes)
- Create: `tests/PackageMigrations.php`
- Create: `tests/Feature/Database/MigrationPublishingTest.php`
- Create: `tests/Feature/Database/CoreMigrationsTest.php`
- Create: `tests/Feature/Database/PgvectorStoreTest.php`

**Interfaces:**
- Consumes: `TestDatabase`, the `database` group (Task 1).
- Produces: `Peralta\AgentKit\Tests\PackageMigrations::in(string $directory): array` returning `list<Illuminate\Database\Migrations\Migration>` in file-name order, where `$directory` is relative to the package root (for example `'database/knowledge/pgvector'`). The `MigrationPublishingTest::TAGS` constant that Task 3 extends.

- [ ] **Step 1: Add the `PackageMigrations` helper**

Create `tests/PackageMigrations.php`:

```php
<?php

namespace Peralta\AgentKit\Tests;

use Illuminate\Database\Migrations\Migration;

final class PackageMigrations
{
    /**
     * Fresh instances of the package migrations in one directory, in file-name order.
     *
     * @param  string  $directory  relative to the package root, e.g. "database/migrations"
     * @return list<Migration>
     */
    public static function in(string $directory): array
    {
        $files = glob(dirname(__DIR__) . '/' . trim($directory, '/') . '/*.php') ?: [];
        sort($files);

        return array_map(static fn (string $file): Migration => require $file, $files);
    }
}
```

- [ ] **Step 2: Write the failing publishing test**

Create `tests/Feature/Database/MigrationPublishingTest.php` (no group: it does not touch the database):

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\ServiceProvider;
use Peralta\AgentKit\AgentKitServiceProvider;
use Peralta\AgentKit\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MigrationPublishingTest extends TestCase
{
    /** Publish tag => package directory it publishes into the application's database/migrations. */
    private const TAGS = [
        'agent-kit-migrations' => 'database/migrations',
        'agent-kit-pgvector-migrations' => 'database/knowledge/pgvector',
    ];

    public function test_each_migration_tag_publishes_one_package_directory_into_the_app_migrations(): void
    {
        foreach (self::TAGS as $tag => $directory) {
            $this->assertSame(
                [$this->packagePath($directory) => $this->app->databasePath('migrations')],
                $this->resolved(ServiceProvider::pathsToPublish(AgentKitServiceProvider::class, $tag)),
                $tag,
            );
        }
    }

    public function test_the_default_tag_ships_only_the_portable_migrations(): void
    {
        $this->assertSame(
            [
                '2026_05_05_000001_create_agent_messages_table.php',
                '2026_08_08_000003_create_agent_kit_metrics_table.php',
            ],
            $this->fileNames('database/migrations'),
        );
    }

    public function test_every_packaged_migration_belongs_to_exactly_one_tag(): void
    {
        $tagDirectories = array_map(fn (string $directory): string => $this->packagePath($directory), self::TAGS);

        foreach ($this->packagedMigrations() as $file) {
            $owners = array_filter($tagDirectories, fn (string $directory): bool => dirname($file) === $directory);

            $this->assertCount(1, $owners, basename($file) . ' must belong to exactly one publish tag.');
        }
    }

    private function packagePath(string $relative): string
    {
        return (string) realpath(dirname(__DIR__, 3) . '/' . $relative);
    }

    /**
     * @param  array<string, string>  $paths
     * @return array<string, string>
     */
    private function resolved(array $paths): array
    {
        $resolved = [];
        foreach ($paths as $from => $to) {
            $resolved[(string) realpath($from)] = $to;
        }

        return $resolved;
    }

    /** @return list<string> */
    private function fileNames(string $directory): array
    {
        $names = array_map('basename', glob($this->packagePath($directory) . '/*.php') ?: []);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function packagedMigrations(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->packagePath('database'), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = (string) $file->getRealPath();
            }
        }
        sort($files);

        return $files;
    }
}
```

- [ ] **Step 3: Write the failing core-migrations test**

Create `tests/Feature/Database/CoreMigrationsTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class CoreMigrationsTest extends TestCase
{
    public function test_core_migrations_create_and_drop_their_tables(): void
    {
        $migrations = PackageMigrations::in('database/migrations');
        $this->assertCount(2, $migrations);

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumns('agent_messages', [
            'id', 'conversation_id', 'role', 'content', 'tool_calls', 'tool_call_id', 'metadata', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('agent_kit_metrics', [
            'id', 'type', 'conversation_id', 'provider', 'model', 'duration_ms', 'payload', 'created_at',
        ]));

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        $this->assertFalse(Schema::hasTable('agent_messages'));
        $this->assertFalse(Schema::hasTable('agent_kit_metrics'));
    }
}
```

- [ ] **Step 4: Write the pgvector test**

Create `tests/Feature/Database/PgvectorStoreTest.php`. It runs only on PostgreSQL with the `vector` extension available and is skipped elsewhere:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\PgvectorStore;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class PgvectorStoreTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('agent-kit.knowledge.stores.pgvector.connection', 'testing');
        $app['config']->set('agent-kit.knowledge.embedders.openai.dimensions', 3);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector tests need AGENT_KIT_TEST_DB_CONNECTION=pgsql.');
        }

        if (DB::selectOne("select 1 as available from pg_available_extensions where name = 'vector'") === null) {
            $this->markTestSkipped('pgvector tests need the vector extension, e.g. the pgvector/pgvector image.');
        }
    }

    public function test_pgvector_migration_and_store_rank_chunks_by_cosine_similarity(): void
    {
        $migrations = PackageMigrations::in('database/knowledge/pgvector');
        $this->assertCount(1, $migrations);
        [$migration] = $migrations;

        $migration->up();

        $store = new PgvectorStore(connection: 'testing', table: 'knowledge_chunks');
        $store->insertBatch([
            new KnowledgeChunk('tenant', 'faq', 'a.pdf', 'far', [], [0.0, 1.0, 0.0]),
            new KnowledgeChunk('tenant', 'faq', 'b.pdf', 'near', ['page' => 3], [1.0, 0.1, 0.0]),
            new KnowledgeChunk('other', 'faq', 'c.pdf', 'other tenant', [], [1.0, 0.0, 0.0]),
        ]);

        $results = $store->search([1.0, 0.0, 0.0], 'tenant', 'faq', 5, 0.0);

        $this->assertSame(['near', 'far'], array_map(fn (KnowledgeChunk $chunk) => $chunk->content, $results));
        $this->assertSame(['page' => 3], $results[0]->metadata);
        $this->assertGreaterThan($results[1]->relevance, $results[0]->relevance);

        $migration->down();

        $this->assertFalse(Schema::hasTable('knowledge_chunks'));
    }
}
```

- [ ] **Step 5: Run the new tests and confirm they fail**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Database`
Expected: `MigrationPublishingTest` fails (no paths for `agent-kit-pgvector-migrations`; three files in `database/migrations`), `CoreMigrationsTest` fails (`3` migrations instead of `2`), `PgvectorStoreTest` is skipped on SQLite.

Run: `AGENT_KIT_TEST_DB_CONNECTION=pgsql AGENT_KIT_TEST_DB_PORT=54329 AGENT_KIT_TEST_DB_PASSWORD=agentkit vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Database/PgvectorStoreTest.php`
Expected: FAIL, `0` migrations in `database/knowledge/pgvector`.

- [ ] **Step 6: Move the migration and publish it under its own tag**

```bash
mkdir -p database/knowledge/pgvector
git mv database/migrations/2026_05_05_000002_create_knowledge_chunks_table.php database/knowledge/pgvector/
```

In `src/AgentKitServiceProvider.php`, `boot()`, replace the migrations `publishes()` call with:

```php
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'agent-kit-migrations');

            // Knowledge tables have one tag per store, so `migrate` only needs the database the chosen store uses.
            $this->publishes([
                __DIR__ . '/../database/knowledge/pgvector' => database_path('migrations'),
            ], 'agent-kit-pgvector-migrations');
```

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Database`
Expected: OK, with `PgvectorStoreTest` skipped.

Run the three server commands from Global Constraints.
Expected: `OK` on MySQL and MariaDB (pgvector test skipped), and `OK` on PostgreSQL with `PgvectorStoreTest` executed, not skipped (check with `--testdox` if unsure).

Run: `vendor/bin/phpunit --colors=never --no-coverage`
Expected: the whole suite passes on SQLite.

- [ ] **Step 8: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add database src/AgentKitServiceProvider.php tests/PackageMigrations.php tests/Feature/Database
git commit -m "feat: publish the pgvector migration under its own tag

The default agent-kit-migrations tag now ships only the portable
agent_messages and agent_kit_metrics migrations, so php artisan migrate no
longer requires PostgreSQL with pgvector.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Database-store migration, publish tag and configuration

**Files:**
- Create: `database/knowledge/database/2026_09_18_000004_create_database_store_knowledge_chunks_table.php`
- Create: `tests/Feature/Database/DatabaseStoreMigrationTest.php`
- Modify: `tests/Feature/Database/MigrationPublishingTest.php` (`TAGS` constant)
- Modify: `src/AgentKitServiceProvider.php` (`boot()` publishes)
- Modify: `config/agent-kit.php` (`knowledge.store` comment and `knowledge.stores`)

**Interfaces:**
- Consumes: `PackageMigrations::in()` (Task 2).
- Produces: table columns `id, tenant_id, collection, source, content, metadata, embedding, created_at, updated_at` on the table named by `agent-kit.knowledge.stores.database.table` (default `knowledge_chunks`) on the connection `agent-kit.knowledge.stores.database.connection` (`null` or empty = default connection). Config keys `agent-kit.knowledge.stores.database.{driver,connection,table}`.

- [ ] **Step 1: Extend the publishing test**

In `tests/Feature/Database/MigrationPublishingTest.php`, replace the `TAGS` constant with:

```php
    private const TAGS = [
        'agent-kit-migrations' => 'database/migrations',
        'agent-kit-pgvector-migrations' => 'database/knowledge/pgvector',
        'agent-kit-database-store-migrations' => 'database/knowledge/database',
    ];
```

- [ ] **Step 2: Write the failing migration test**

Create `tests/Feature/Database/DatabaseStoreMigrationTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class DatabaseStoreMigrationTest extends TestCase
{
    public function test_migration_creates_and_drops_the_configured_table(): void
    {
        config()->set('agent-kit.knowledge.stores.database.table', 'kb_chunks');
        $migrations = PackageMigrations::in('database/knowledge/database');
        $this->assertCount(1, $migrations);
        [$migration] = $migrations;

        $migration->up();

        $this->assertTrue(Schema::hasColumns('kb_chunks', [
            'id', 'tenant_id', 'collection', 'source', 'content', 'metadata', 'embedding', 'created_at', 'updated_at',
        ]));

        $migration->down();

        $this->assertFalse(Schema::hasTable('kb_chunks'));
    }

    public function test_an_empty_connection_name_means_the_default_connection(): void
    {
        config()->set('agent-kit.knowledge.stores.database.connection', '');
        [$migration] = PackageMigrations::in('database/knowledge/database');

        $migration->up();

        $this->assertTrue(Schema::hasTable('knowledge_chunks'));
    }

    public function test_the_store_is_configured_with_the_default_table(): void
    {
        $config = config('agent-kit.knowledge.stores.database');

        $this->assertSame('database', $config['driver']);
        $this->assertSame('knowledge_chunks', $config['table']);
        $this->assertArrayHasKey('connection', $config);
    }
}
```

- [ ] **Step 3: Run the tests and confirm they fail**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Database`
Expected: failures in `MigrationPublishingTest` (no paths for the new tag) and `DatabaseStoreMigrationTest` (no migration, no config).

- [ ] **Step 4: Create the migration**

Create `database/knowledge/database/2026_09_18_000004_create_database_store_knowledge_chunks_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection($this->connectionName())->create($this->tableName(), function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 64);
            $t->string('collection', 64);
            $t->string('source');
            $t->longText('content');
            // Nullable because MySQL rejects literal defaults on JSON columns; the store always writes it.
            $t->json('metadata')->nullable();
            // Base64 of the L2-normalised embedding packed as little-endian float32.
            $t->longText('embedding');
            $t->timestamps();

            $t->index('tenant_id');
            $t->index(['tenant_id', 'collection']);
            $t->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->dropIfExists($this->tableName());
    }

    private function connectionName(): ?string
    {
        return config('agent-kit.knowledge.stores.database.connection') ?: null;
    }

    private function tableName(): string
    {
        return config('agent-kit.knowledge.stores.database.table', 'knowledge_chunks');
    }
};
```

- [ ] **Step 5: Publish it under its own tag**

In `src/AgentKitServiceProvider.php`, `boot()`, directly after the `agent-kit-pgvector-migrations` `publishes()` call, add:

```php
            $this->publishes([
                __DIR__ . '/../database/knowledge/database' => database_path('migrations'),
            ], 'agent-kit-database-store-migrations');
```

- [ ] **Step 6: Add the configuration**

In `config/agent-kit.php`, replace the line

```php
        'store' => env('AGENT_KNOWLEDGE_STORE', 'pgvector'),
```

with

```php
        // Opções: pgvector (PostgreSQL + pgvector), qdrant, database (qualquer banco do Laravel)
        'store' => env('AGENT_KNOWLEDGE_STORE', 'pgvector'),
```

and add this entry to `'stores' => [ … ]`, after the `'qdrant' => [ … ],` entry:

```php
            'database' => [
                'driver' => 'database',
                // null = conexão padrão da aplicação (MySQL, MariaDB, PostgreSQL ou SQLite).
                // Os embeddings ficam numa tabela comum e são ranqueados em PHP: indicado para
                // bases de até alguns milhares de chunks por tenant e coleção.
                'connection' => env('AGENT_KNOWLEDGE_DB'),
                'table' => 'knowledge_chunks',
            ],
```

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Database`
Expected: OK (pgvector test skipped).

Run the three server commands from Global Constraints.
Expected: `OK` on all three.

- [ ] **Step 8: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add database/knowledge/database src/AgentKitServiceProvider.php config/agent-kit.php tests/Feature/Database
git commit -m "feat: add the portable knowledge_chunks migration for the database store

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `DatabaseVectorStore`

**Files:**
- Create: `src/Knowledge/Stores/DatabaseVectorStore.php`
- Create: `tests/Unit/Knowledge/DatabaseVectorStoreTest.php`
- Create: `tests/Feature/DatabaseVectorStoreBindingTest.php`
- Modify: `src/AgentKitServiceProvider.php` (import and `registerKnowledge()` match arm)

**Interfaces:**
- Consumes: the migration and config from Task 3; `PackageMigrations::in()`; `KnowledgeStore` contract (`src/Knowledge/Contracts/KnowledgeStore.php`); `KnowledgeChunk` (`src/Knowledge/KnowledgeChunk.php`, constructor `(tenantId, collection, source, content, metadata = [], embedding = [], relevance = null, id = null)`); `KnowledgeStoreException` (`src/Exceptions/KnowledgeStoreException.php`).
- Produces: `Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore` with constructor `(?string $connection = null, string $table = 'knowledge_chunks', int $scanPageSize = 1000)` implementing `KnowledgeStore`; container resolves it when `agent-kit.knowledge.stores.<name>.driver` is `database`.

- [ ] **Step 1: Write the failing behaviour tests**

Create `tests/Unit/Knowledge/DatabaseVectorStoreTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Knowledge;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Exceptions\KnowledgeStoreException;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('database')]
class DatabaseVectorStoreTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        foreach (PackageMigrations::in('database/knowledge/database') as $migration) {
            $migration->up();
        }
    }

    public function test_search_returns_the_closest_chunks_first_with_their_data(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([0.0, 1.0, 0.0], 'far', source: 'a.pdf'),
            $this->chunk([1.0, 0.2, 0.0], 'near', source: 'b.pdf', metadata: ['page' => 3]),
            $this->chunk([1.0, 0.0, 0.0], 'exact', source: 'c.pdf'),
        ]);

        $results = $store->search([1.0, 0.0, 0.0], 'tenant', 'faq', 5);

        $this->assertSame(['exact', 'near', 'far'], $this->contents($results));
        $this->assertEqualsWithDelta(1.0, $results[0]->relevance, 1e-6);
        $this->assertSame('b.pdf', $results[1]->source);
        $this->assertSame(['page' => 3], $results[1]->metadata);
        $this->assertSame('tenant', $results[1]->tenantId);
        $this->assertSame('faq', $results[1]->collection);
        $this->assertIsInt($results[1]->id);
        $this->assertSame([], $results[1]->embedding);
    }

    public function test_relevance_is_cosine_similarity_even_for_unnormalised_vectors(): void
    {
        $store = new DatabaseVectorStore();
        $store->insert($this->chunk([3.0, 4.0]));

        $results = $store->search([8.0, 6.0], 'tenant');

        $this->assertEqualsWithDelta(0.96, $results[0]->relevance, 1e-6);
    }

    public function test_search_is_scoped_to_the_tenant_and_optionally_to_the_collection(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([1.0, 0.0], 'faq of tenant'),
            $this->chunk([1.0, 0.0], 'manual of tenant', collection: 'manual'),
            $this->chunk([1.0, 0.0], 'faq of other', tenant: 'other'),
        ]);

        $this->assertSame(['faq of tenant'], $this->contents($store->search([1.0, 0.0], 'tenant', 'faq')));
        $this->assertSame(['faq of tenant', 'manual of tenant'], $this->contents($store->search([1.0, 0.0], 'tenant')));
        $this->assertSame([], $store->search([1.0, 0.0], 'nobody'));
    }

    public function test_min_relevance_filters_and_limit_truncates(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([1.0, 0.0], 'a'),
            $this->chunk([1.0, 0.5], 'b'),
            $this->chunk([1.0, 1.0], 'c'),
            $this->chunk([0.0, 1.0], 'd'),
        ]);

        $this->assertSame(['a', 'b'], $this->contents($store->search([1.0, 0.0], 'tenant', limit: 2)));
        $this->assertSame(['a', 'b', 'c'], $this->contents($store->search([1.0, 0.0], 'tenant', minRelevance: 0.7)));
        $this->assertSame([], $store->search([1.0, 0.0], 'tenant', limit: 0));
    }

    public function test_equal_relevance_keeps_insertion_order(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([0.0, 1.0], 'first'),
            $this->chunk([0.0, 2.0], 'second'),
        ]);

        $this->assertSame(['first', 'second'], $this->contents($store->search([0.0, 1.0], 'tenant')));
    }

    public function test_search_rejects_a_query_with_different_dimensions(): void
    {
        $store = new DatabaseVectorStore();
        $store->insert($this->chunk([1.0, 0.0, 0.0]));

        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('Re-index');

        $store->search([1.0, 0.0], 'tenant');
    }

    public function test_search_rejects_an_empty_query(): void
    {
        $this->expectException(KnowledgeStoreException::class);

        (new DatabaseVectorStore())->search([], 'tenant');
    }

    public function test_insert_rejects_empty_or_mixed_dimension_embeddings_and_writes_nothing(): void
    {
        $store = new DatabaseVectorStore();

        foreach ([[$this->chunk([])], [$this->chunk([1.0, 0.0]), $this->chunk([1.0, 0.0, 0.0])]] as $batch) {
            try {
                $store->insertBatch($batch);
                $this->fail('Expected a KnowledgeStoreException.');
            } catch (KnowledgeStoreException) {
            }
        }

        $this->assertSame(0, DB::table('knowledge_chunks')->count());
    }

    public function test_zero_vectors_have_zero_relevance(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([0.0, 0.0], 'zero'),
            $this->chunk([1.0, 0.0], 'unit'),
        ]);

        $this->assertSame(['unit' => 1.0, 'zero' => 0.0], $this->relevanceByContent($store->search([1.0, 0.0], 'tenant')));
        $this->assertSame(['zero' => 0.0, 'unit' => 0.0], $this->relevanceByContent($store->search([0.0, 0.0], 'tenant')));
    }

    public function test_search_pages_through_every_row_of_the_tenant(): void
    {
        $store = new DatabaseVectorStore(scanPageSize: 2);
        $store->insertBatch([
            $this->chunk([0.0, 1.0], 'r1'),
            $this->chunk([0.2, 1.0], 'r2'),
            $this->chunk([0.4, 1.0], 'r3'),
            $this->chunk([0.6, 1.0], 'r4'),
            $this->chunk([1.0, 0.0], 'r5'),
        ]);

        DB::enableQueryLog();
        $results = $store->search([1.0, 0.0], 'tenant', limit: 1);
        $scans = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'embedding'));

        $this->assertSame(['r5'], $this->contents($results));
        $this->assertGreaterThan(1, count($scans));
    }

    public function test_a_failed_batch_is_rolled_back(): void
    {
        $store = new DatabaseVectorStore();
        $chunks = array_map(fn (int $i): KnowledgeChunk => $this->chunk([1.0, (float) $i], "chunk {$i}"), range(1, 150));

        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && ++$inserts === 2) {
                throw new RuntimeException('second insert failed');
            }
        });

        try {
            $store->insertBatch($chunks);
            $this->fail('Expected the second insert to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('second insert failed', $e->getMessage());
        }

        $this->assertSame(0, DB::table('knowledge_chunks')->count());
    }

    public function test_delete_by_source_spans_collections_and_delete_by_tenant_clears_the_tenant(): void
    {
        $store = new DatabaseVectorStore();
        $store->insertBatch([
            $this->chunk([1.0, 0.0], source: 'manual.pdf'),
            $this->chunk([1.0, 0.0], collection: 'manual', source: 'manual.pdf'),
            $this->chunk([1.0, 0.0], source: 'faq.pdf'),
            $this->chunk([1.0, 0.0], tenant: 'other', source: 'manual.pdf'),
        ]);

        $this->assertSame(2, $store->deleteBySource('tenant', 'manual.pdf'));
        $this->assertSame(1, $store->deleteByTenant('tenant'));
        $this->assertSame(1, DB::table('knowledge_chunks')->count());
    }

    public function test_unicode_content_and_metadata_round_trip(): void
    {
        $store = new DatabaseVectorStore();
        $metadata = ['título' => 'Seção 3 — prazos', 'tags' => ['frete', 'devolução']];
        $store->insert($this->chunk([1.0], 'Política de devolução 🚚', metadata: $metadata));

        [$result] = $store->search([1.0], 'tenant');

        $this->assertSame('Política de devolução 🚚', $result->content);
        // assertEquals: MySQL JSON columns may reorder object keys.
        $this->assertEquals($metadata, $result->metadata);
    }

    public function test_embeddings_are_stored_as_base64_of_normalised_float32(): void
    {
        (new DatabaseVectorStore())->insert($this->chunk([3.0, 4.0]));

        $this->assertSame(base64_encode(pack('g*', 0.6, 0.8)), DB::table('knowledge_chunks')->value('embedding'));
    }

    public function test_an_empty_connection_name_uses_the_default_connection(): void
    {
        (new DatabaseVectorStore(connection: ''))->insert($this->chunk([1.0]));

        $this->assertSame(1, DB::table('knowledge_chunks')->count());
    }

    private function chunk(
        array $embedding,
        string $content = 'content',
        string $tenant = 'tenant',
        string $collection = 'faq',
        string $source = 'source',
        array $metadata = [],
    ): KnowledgeChunk {
        return new KnowledgeChunk($tenant, $collection, $source, $content, $metadata, $embedding);
    }

    /**
     * @param  KnowledgeChunk[]  $chunks
     * @return list<string>
     */
    private function contents(array $chunks): array
    {
        return array_map(fn (KnowledgeChunk $chunk): string => $chunk->content, $chunks);
    }

    /**
     * @param  KnowledgeChunk[]  $chunks
     * @return array<string, float>
     */
    private function relevanceByContent(array $chunks): array
    {
        return array_combine($this->contents($chunks), array_map(fn (KnowledgeChunk $chunk) => $chunk->relevance, $chunks));
    }
}
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Unit/Knowledge/DatabaseVectorStoreTest.php`
Expected: errors with `Class "Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore" not found`.

- [ ] **Step 3: Implement the store**

Create `src/Knowledge/Stores/DatabaseVectorStore.php`:

```php
<?php

namespace Peralta\AgentKit\Knowledge\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Exceptions\KnowledgeStoreException;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;

/**
 * Knowledge store for any database Laravel supports (MySQL, MariaDB, PostgreSQL, SQLite).
 *
 * Embeddings are stored as base64 of the L2-normalised vector packed as little-endian
 * float32 and ranked in PHP with a dot product, so every search reads all embeddings of
 * the tenant (and collection). Suited to a few thousand chunks per tenant and collection;
 * use pgvector or Qdrant beyond that.
 */
class DatabaseVectorStore implements KnowledgeStore
{
    private const INSERT_CHUNK_SIZE = 100;

    public function __construct(
        protected ?string $connection = null,
        protected string $table = 'knowledge_chunks',
        protected int $scanPageSize = 1000,
    ) {
        $this->connection = $connection !== '' ? $connection : null;
    }

    public function insert(KnowledgeChunk $chunk): void
    {
        $this->insertBatch([$chunk]);
    }

    public function insertBatch(array $chunks): void
    {
        if ($chunks === []) {
            return;
        }

        $this->assertConsistentDimensions($chunks);

        $now = now();
        $rows = [];
        foreach ($chunks as $chunk) {
            $rows[] = [
                'tenant_id' => $chunk->tenantId,
                'collection' => $chunk->collection,
                'source' => $chunk->source,
                'content' => $chunk->content,
                'metadata' => json_encode($chunk->metadata, JSON_UNESCAPED_UNICODE),
                'embedding' => base64_encode($this->pack($chunk->embedding)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One transaction per batch: a document is indexed completely or not at all.
        $this->db()->transaction(function () use ($rows): void {
            foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $batch) {
                $this->db()->table($this->table)->insert($batch);
            }
        });
    }

    public function search(
        array $embedding,
        string $tenantId,
        ?string $collection = null,
        int $limit = 5,
        float $minRelevance = 0.0,
    ): array {
        if ($limit <= 0) {
            return [];
        }

        if ($embedding === []) {
            throw new KnowledgeStoreException('Cannot search the knowledge base with an empty embedding.');
        }

        // Round-trip through float32 so the query has the stored precision and unpack()'s 1-based keys.
        $query = unpack('g*', $this->pack($embedding));
        $scores = $this->score($query, $tenantId, $collection, $minRelevance);

        return $this->hydrate($this->rank($scores, $limit), $scores);
    }

    public function deleteBySource(string $tenantId, string $source): int
    {
        return $this->db()
            ->table($this->table)
            ->where('tenant_id', $tenantId)
            ->where('source', $source)
            ->delete();
    }

    public function deleteByTenant(string $tenantId): int
    {
        return $this->db()
            ->table($this->table)
            ->where('tenant_id', $tenantId)
            ->delete();
    }

    /**
     * @param  array<int, float>  $query  1-based, as unpack() returns it
     * @return array<int, float> relevance by row id, only rows at or above $minRelevance
     */
    private function score(array $query, string $tenantId, ?string $collection, float $minRelevance): array
    {
        $dimensions = count($query);
        $scores = [];

        $rows = $this->db()
            ->table($this->table)
            ->select(['id', 'embedding'])
            ->where('tenant_id', $tenantId)
            ->when($collection !== null && $collection !== '', fn ($builder) => $builder->where('collection', $collection))
            ->lazyById($this->scanPageSize);

        foreach ($rows as $row) {
            $vector = $this->decode((string) $row->embedding);

            if (count($vector) !== $dimensions) {
                throw new KnowledgeStoreException(sprintf(
                    'Knowledge chunk %d has a %d-dimension embedding but the query has %d dimensions. '
                    . 'Re-index the knowledge base after changing the embedder.',
                    $row->id,
                    count($vector),
                    $dimensions,
                ));
            }

            $relevance = 0.0;
            foreach ($query as $i => $value) {
                $relevance += $value * $vector[$i];
            }
            $relevance = max(-1.0, min(1.0, $relevance));

            if ($relevance >= $minRelevance) {
                $scores[(int) $row->id] = $relevance;
            }
        }

        return $scores;
    }

    /**
     * @param  array<int, float>  $scores
     * @return list<int> the best ids first, ties broken by id
     */
    private function rank(array $scores, int $limit): array
    {
        $ids = array_keys($scores);
        usort($ids, fn (int $a, int $b): int => [$scores[$b], $a] <=> [$scores[$a], $b]);

        return array_slice($ids, 0, $limit);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, float>  $scores
     * @return KnowledgeChunk[]
     */
    private function hydrate(array $ids, array $scores): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db()
            ->table($this->table)
            ->select(['id', 'tenant_id', 'collection', 'source', 'content', 'metadata'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->id);

        $chunks = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);

            if ($row === null) {
                continue; // deleted between the scan and this query
            }

            $chunks[] = new KnowledgeChunk(
                tenantId: $row->tenant_id,
                collection: $row->collection,
                source: $row->source,
                content: $row->content,
                metadata: $this->decodeMetadata($row->metadata),
                embedding: [],
                relevance: $scores[$id],
                id: $id,
            );
        }

        return $chunks;
    }

    /** @param  KnowledgeChunk[]  $chunks */
    private function assertConsistentDimensions(array $chunks): void
    {
        $dimensions = null;

        foreach ($chunks as $chunk) {
            $size = count($chunk->embedding);

            if ($size === 0) {
                throw new KnowledgeStoreException("Knowledge chunk from source '{$chunk->source}' has an empty embedding.");
            }

            $dimensions ??= $size;

            if ($size !== $dimensions) {
                throw new KnowledgeStoreException(
                    "All embeddings in a batch must have the same dimensions; got {$dimensions} and {$size}.",
                );
            }
        }
    }

    /** L2-normalises the vector and packs it as little-endian float32. A zero vector stays zero. */
    private function pack(array $vector): string
    {
        $values = array_map('floatval', array_values($vector));
        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $values)));

        if ($norm > 0.0) {
            $values = array_map(fn (float $value): float => $value / $norm, $values);
        }

        return pack('g*', ...$values);
    }

    /** @return array<int, float> 1-based, as unpack() returns it */
    private function decode(string $encoded): array
    {
        $binary = base64_decode($encoded, true);

        if ($binary === false || strlen($binary) % 4 !== 0) {
            throw new KnowledgeStoreException('Stored embedding is not base64-encoded float32 data.');
        }

        return $binary === '' ? [] : unpack('g*', $binary);
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
```

- [ ] **Step 4: Run the behaviour tests and confirm they pass**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Unit/Knowledge/DatabaseVectorStoreTest.php`
Expected: `OK (15 tests, …)`.

- [ ] **Step 5: Write the failing binding test**

Create `tests/Feature/DatabaseVectorStoreBindingTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class DatabaseVectorStoreBindingTest extends TestCase
{
    public function test_it_resolves_the_database_store_with_the_configured_connection_and_table(): void
    {
        config()->set('agent-kit.knowledge.store', 'database');
        config()->set('agent-kit.knowledge.stores.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'kb_custom',
        ]);
        foreach (PackageMigrations::in('database/knowledge/database') as $migration) {
            $migration->up();
        }

        $store = $this->app->make(KnowledgeStore::class);
        $store->insert(new KnowledgeChunk('tenant', 'faq', 'source', 'content', [], [1.0, 0.0]));

        $this->assertInstanceOf(DatabaseVectorStore::class, $store);
        $this->assertSame(1, DB::connection('testing')->table('kb_custom')->count());
    }
}
```

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Feature/DatabaseVectorStoreBindingTest.php`
Expected: FAIL with `Driver de knowledge store 'database' inválido.`

- [ ] **Step 6: Bind the driver**

In `src/AgentKitServiceProvider.php` add the import next to the other knowledge stores:

```php
use Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore;
```

and in `registerKnowledge()`, inside the `KnowledgeStore` `match ($cfg['driver'])`, add this arm after the `'qdrant' => …,` arm:

```php
                'database' => new DatabaseVectorStore(
                    connection: $cfg['connection'] ?? null,
                    table: $cfg['table'] ?? 'knowledge_chunks',
                ),
```

- [ ] **Step 7: Run everything and confirm it passes**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Feature/DatabaseVectorStoreBindingTest.php tests/Unit/Knowledge/DatabaseVectorStoreTest.php`
Expected: OK.

Run the three server commands from Global Constraints.
Expected: `OK` on MySQL, MariaDB and PostgreSQL.

Run: `vendor/bin/phpunit --colors=never --no-coverage`
Expected: the whole suite passes on SQLite.

- [ ] **Step 8: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add src/Knowledge/Stores/DatabaseVectorStore.php src/AgentKitServiceProvider.php tests/Unit/Knowledge/DatabaseVectorStoreTest.php tests/Feature/DatabaseVectorStoreBindingTest.php
git commit -m "feat: add a portable database knowledge store

DatabaseVectorStore keeps L2-normalised float32 embeddings as base64 text
and ranks them by dot product in PHP, so RAG works on MySQL, MariaDB,
PostgreSQL without pgvector and SQLite.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: GitHub Actions workflow

**Files:**
- Create: `.github/workflows/tests.yml`
- Modify: `CONTRIBUTING.md` (one sentence in the section added by Task 1)

**Interfaces:**
- Consumes: the `database` group and `AGENT_KIT_TEST_DB_*` variables (Task 1).

- [ ] **Step 1: Write the workflow**

Create `.github/workflows/tests.yml`:

```yaml
name: tests

on:
  push:
    branches: [main]
  pull_request:

permissions:
  contents: read

concurrency:
  group: tests-${{ github.ref }}
  cancel-in-progress: true

jobs:
  suite:
    name: Suite (SQLite)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_sqlite
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Run the suite
        run: vendor/bin/phpunit --colors=never --no-coverage

  databases:
    name: Database group (${{ matrix.name }})
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        include:
          - name: MySQL 8.4
            image: mysql:8.4
            connection: mysql
            port: 3306
            health: mysqladmin ping -h 127.0.0.1 -uroot -pagentkit
          - name: MariaDB 11.8
            image: mariadb:11.8
            connection: mariadb
            port: 3306
            health: healthcheck.sh --connect --innodb_initialized
          - name: PostgreSQL 17 + pgvector
            image: pgvector/pgvector:pg17
            connection: pgsql
            port: 5432
            health: pg_isready -h 127.0.0.1 -U postgres
    services:
      database:
        image: ${{ matrix.image }}
        env:
          MYSQL_ROOT_PASSWORD: agentkit
          MYSQL_DATABASE: agent_kit_test
          MARIADB_ROOT_PASSWORD: agentkit
          MARIADB_DATABASE: agent_kit_test
          POSTGRES_PASSWORD: agentkit
          POSTGRES_DB: agent_kit_test
        ports:
          - ${{ matrix.port }}:${{ matrix.port }}
        options: >-
          --health-cmd "${{ matrix.health }}"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 30
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_mysql, pdo_pgsql, pdo_sqlite
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Run the database group
        env:
          AGENT_KIT_TEST_DB_CONNECTION: ${{ matrix.connection }}
          AGENT_KIT_TEST_DB_HOST: 127.0.0.1
          AGENT_KIT_TEST_DB_PORT: ${{ matrix.port }}
          AGENT_KIT_TEST_DB_DATABASE: agent_kit_test
          AGENT_KIT_TEST_DB_USERNAME: ${{ matrix.connection == 'pgsql' && 'postgres' || 'root' }}
          AGENT_KIT_TEST_DB_PASSWORD: agentkit
        run: vendor/bin/phpunit --colors=never --no-coverage --group database
```

- [ ] **Step 2: Lint the workflow**

Run: `docker run --rm -v "$PWD:/repo" --workdir /repo rhysd/actionlint:latest -color`
Expected: no output and exit code 0. Fix any reported problem and re-run.

- [ ] **Step 3: Mention CI in `CONTRIBUTING.md`**

At the end of the "Contra MySQL, MariaDB ou PostgreSQL" section added in Task 1, append this paragraph:

```markdown
O CI (`.github/workflows/tests.yml`) roda a suíte completa em SQLite e o grupo
`database` em MySQL 8.4, MariaDB 11.8 e PostgreSQL 17 com pgvector a cada push na
`main` e em todo pull request.
```

- [ ] **Step 4: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add .github/workflows/tests.yml CONTRIBUTING.md
git commit -m "ci: run the suite on SQLite and the database group on MySQL, MariaDB and PostgreSQL

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

The workflow itself is proven when the pull request runs it; the controller checks that run.

---

### Task 6: Documentation, CHANGELOG and measured envelope

**Files:**
- Create: `tests/Feature/Database/DatabaseDocumentationTest.php`
- Create (not committed, `build/` is git-ignored): `build/DatabaseVectorStoreBenchmark.php`
- Modify: `README.md`, `SETUP.md`, `ARCHITECTURE.md`, `.env.example`, `CHANGELOG.md`, `composer.json`

**Interfaces:**
- Consumes: tags (Tasks 2–3), `DatabaseVectorStore` (Task 4), `AGENT_KIT_TEST_DB_*` (Task 1).

- [ ] **Step 1: Write the failing documentation test**

Create `tests/Feature/Database/DatabaseDocumentationTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature\Database;

use PHPUnit\Framework\TestCase;

final class DatabaseDocumentationTest extends TestCase
{
    public function test_docs_name_the_migration_tags_and_the_database_store(): void
    {
        $root = dirname(__DIR__, 3);
        $read = fn (string $file): string => (string) file_get_contents($root . '/' . $file);
        $readme = $read('README.md');
        $setup = $read('SETUP.md');
        $changelog = $read('CHANGELOG.md');

        foreach (['agent-kit-migrations', 'agent-kit-pgvector-migrations', 'agent-kit-database-store-migrations'] as $tag) {
            $this->assertStringContainsString($tag, $readme, $tag);
            $this->assertStringContainsString($tag, $setup, $tag);
        }
        foreach (['agent-kit-pgvector-migrations', 'agent-kit-database-store-migrations', 'AGENT_KNOWLEDGE_STORE=database'] as $needle) {
            $this->assertStringContainsString($needle, $changelog, $needle);
        }

        $this->assertStringContainsString('AGENT_KNOWLEDGE_STORE=database', $readme);
        $this->assertMatchesRegularExpression('/^# Knowledge Store: .*database/m', $read('.env.example'));
        $this->assertStringContainsString('AGENT_KIT_TEST_DB_CONNECTION', $read('CONTRIBUTING.md'));

        foreach (['sem tag própria', 'a migration do pgvector continua sendo executada', 'compartilham a mesma tag'] as $stale) {
            $this->assertStringNotContainsString($stale, $readme, $stale);
            $this->assertStringNotContainsString($stale, $setup, $stale);
        }
    }
}
```

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Database/DatabaseDocumentationTest.php`
Expected: FAIL (README does not name `agent-kit-pgvector-migrations` yet).

- [ ] **Step 2: Measure the store on MySQL**

Create `build/DatabaseVectorStoreBenchmark.php` (the file stays out of git):

```php
<?php

use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore;
use Peralta\AgentKit\Tests\PackageMigrations;
use Peralta\AgentKit\Tests\TestCase;

final class DatabaseVectorStoreBenchmark extends TestCase
{
    public function test_search_latency_by_candidate_count(): void
    {
        foreach (PackageMigrations::in('database/knowledge/database') as $migration) {
            $migration->up();
        }

        mt_srand(42);
        $vector = function (): array {
            $values = [];
            for ($i = 0; $i < 1536; $i++) {
                $values[] = mt_rand() / mt_getrandmax() * 2 - 1;
            }

            return $values;
        };
        $store = new DatabaseVectorStore();

        foreach ([1000, 5000, 10000] as $size) {
            $tenant = "tenant-{$size}";
            for ($done = 0; $done < $size; $done += 500) {
                $store->insertBatch(array_map(
                    fn () => new KnowledgeChunk($tenant, 'faq', 'bench', str_repeat('texto ', 300), [], $vector()),
                    range(1, min(500, $size - $done)),
                ));
            }

            $query = $vector();
            $store->search($query, $tenant, 'faq');
            $times = [];
            for ($run = 0; $run < 5; $run++) {
                $start = hrtime(true);
                $store->search($query, $tenant, 'faq');
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);

            fwrite(STDERR, sprintf("%d chunks: median %.0f ms (min %.0f, max %.0f)\n", $size, $times[2], $times[0], $times[4]));
        }

        $this->assertTrue(true);
    }
}
```

Run: `AGENT_KIT_TEST_DB_CONNECTION=mysql AGENT_KIT_TEST_DB_PORT=33061 AGENT_KIT_TEST_DB_PASSWORD=agentkit vendor/bin/phpunit --colors=never --no-coverage build/DatabaseVectorStoreBenchmark.php`
Expected: three lines like `1000 chunks: median … ms`. Keep the three medians; Step 4 uses them. Record the machine (`sysctl -n machdep.cpu.brand_string` on macOS) for the report.

- [ ] **Step 3: Update `README.md`**

1. Replace line 3 (`Toolkit Laravel … e RAG via pgvector.`) with:

```markdown
Toolkit Laravel para construir agentes de IA com suporte a múltiplos providers (OpenAI, Anthropic, Gemini, DeepSeek), tools customizadas e RAG via pgvector, Qdrant ou o próprio banco relacional (MySQL, MariaDB, PostgreSQL ou SQLite).
```

2. In "## Instalação", delete the whole `**Pré-requisito:** …` paragraph (from `**Pré-requisito:**` up to `as demais, sem tag própria.`). Keep the Packagist note and the install code block. Directly after the install code block (the one ending in `php artisan migrate`), insert:

````markdown
A tag `agent-kit-migrations` publica só as tabelas de conversas (`agent_messages`) e de
métricas (`agent_kit_metrics`). Elas usam apenas tipos portáveis e são testadas no CI em
MySQL 8.4, MariaDB 11.8, PostgreSQL 17 e SQLite.

A tabela da knowledge base tem uma tag por store. Publique só a do store que você usa,
antes do `migrate`:

| Store (`AGENT_KNOWLEDGE_STORE`) | Tag | Banco |
|---|---|---|
| `pgvector` (padrão) | `agent-kit-pgvector-migrations` | PostgreSQL com a extensão pgvector |
| `database` | `agent-kit-database-store-migrations` | MySQL, MariaDB, PostgreSQL ou SQLite |
| `qdrant` | nenhuma | a coleção é criada no Qdrant na primeira escrita |

```bash
# exemplo com pgvector
php artisan vendor:publish --tag=agent-kit-pgvector-migrations
php artisan migrate
```

A migration do pgvector executa `CREATE EXTENSION IF NOT EXISTS vector` na conexão
`pgsql` (ou na de `AGENT_KNOWLEDGE_DB`), então esse banco precisa estar configurado em
`config/database.php` antes do `migrate`. Quem não usa RAG não publica nenhuma tag de
knowledge base.
````

3. In "## Atualizando", after the existing paragraph, add:

```markdown
Vindo da v0.2.x: a migration do `knowledge_chunks` para pgvector saiu da tag
`agent-kit-migrations` e passou para `agent-kit-pgvector-migrations`. Quem já publicou as
migrations não precisa fazer nada, porque o arquivo mantém o mesmo nome. Instalações novas
com pgvector publicam as duas tags.
```

4. After the "### Qdrant" subsection (after the paragraph that ends `através de filtros de payload.`), add a new subsection. Put the three medians from Step 2 in the table, rounded to the nearest 10 ms below 1 s and written as seconds with one decimal (`1,1 s`) from 1 s up:

````markdown
### Banco relacional (MySQL, MariaDB, PostgreSQL, SQLite)

O store `database` guarda os embeddings numa tabela comum e calcula a similaridade de
cosseno em PHP. Ele funciona em qualquer banco suportado pelo Laravel, inclusive MySQL 8
Community, que não tem busca vetorial nativa.

```env
AGENT_KNOWLEDGE_STORE=database
# vazio = conexão padrão da aplicação
AGENT_KNOWLEDGE_DB=
```

```bash
php artisan vendor:publish --tag=agent-kit-database-store-migrations
php artisan migrate
```

Cada busca lê todos os embeddings do tenant, e da coleção quando ela é informada, então o
custo cresce de forma linear. Medido em MySQL 8.4 com embeddings de 1536 dimensões:

| Chunks por tenant e coleção | Tempo por busca |
|---|---|
| 1.000 | <median for 1000> |
| 5.000 | <median for 5000> |
| 10.000 | <median for 10000> |

Use para FAQs, políticas e manuais de até alguns milhares de chunks por tenant e coleção.
Acima disso, prefira pgvector ou Qdrant. Trocar de embedder exige reindexar: uma busca com
dimensão diferente da armazenada lança `KnowledgeStoreException`.
````

The three `<median for …>` cells are the only values you fill in from Step 2; no angle brackets may remain in the file.

5. In "## Documentação", replace `- [SETUP.md](SETUP.md) — guia completo de configuração (PostgreSQL + pgvector, Redis, RAG)` with `- [SETUP.md](SETUP.md) — guia completo de configuração (PostgreSQL + pgvector ou MySQL/MariaDB, Redis, RAG)`.

- [ ] **Step 4: Update `SETUP.md`**

1. Replace line 3 with:

```markdown
Configuração passo a passo para usar Agent Kit com PostgreSQL + Redis + RAG (Knowledge Base). Para MySQL ou MariaDB, siga as notas "MySQL/MariaDB" dos Passos 1 e 3.
```

2. In "Pré-requisitos", replace `- PostgreSQL com extensão pgvector` with `- PostgreSQL com extensão pgvector, ou MySQL/MariaDB com o store \`database\` ou Qdrant`.

3. In "Passo 1", in the install code block, add `php artisan vendor:publish --tag=agent-kit-pgvector-migrations` as the line after `php artisan vendor:publish --tag=agent-kit-migrations`. Directly after that code block, add:

```markdown
> MySQL/MariaDB: troque a tag do pgvector por `agent-kit-database-store-migrations` para
> usar o store `database`, ou não publique nenhuma tag de knowledge base se for usar Qdrant.
```

4. In "Passo 2", replace the comment line `# Knowledge Base (PostgreSQL + pgvector; Qdrant é alternativa de store, mas a migration do pgvector continua sendo executada)` with `# Knowledge Base: pgvector (PostgreSQL), database (MySQL, MariaDB, PostgreSQL ou SQLite) ou qdrant`.

5. In "Passo 3", replace the first line of the warning (`> ⚠️ Este passo exige o PostgreSQL com pgvector **já rodando e configurado** como a`) with `> ⚠️ Com a tag \`agent-kit-pgvector-migrations\` publicada, este passo exige o PostgreSQL com pgvector **já rodando e configurado** como a`. Keep the rest of the warning. Add after the warning block:

```markdown
> MySQL/MariaDB: sem a tag do pgvector, o `migrate` cria as tabelas do pacote no banco
> padrão da aplicação, e a `knowledge_chunks` só se você publicou
> `agent-kit-database-store-migrations`.
```

Replace the "Isso cria:" list with:

```markdown
Isso cria:
1. **Tabela `agent_messages`** - Histórico de mensagens
2. **Tabela `agent_kit_metrics`** - Métricas de uso (gravadas com `AGENT_KIT_ANALYTICS_PERSIST=true`)
3. **Tabela `knowledge_chunks`** - Documentos indexados para RAG (pgvector ou store `database`)
4. **Extensão `pgvector`** no PostgreSQL - Só com a tag do pgvector
```

6. Rename the heading `## ✔️ Passo 4: Verificar pgvector` to `## ✔️ Passo 4: Verificar pgvector (somente pgvector)`.

7. Replace the sentence `A tabela \`agent_kit_metrics\` já foi criada no Passo 3: as três migrations do pacote\ncompartilham a mesma tag \`agent-kit-migrations\`.` (two lines) with:

```markdown
A tabela `agent_kit_metrics` já foi criada no Passo 3: ela vem na tag
`agent-kit-migrations`, junto com `agent_messages`.
```

Keep the following sentence (`Para persistir métricas, basta ligar …`) as is.

8. In "Checklist Final", replace `- [ ] PostgreSQL com pgvector` with `- [ ] PostgreSQL com pgvector, ou store \`database\`/Qdrant no MySQL/MariaDB`.

- [ ] **Step 5: Update `.env.example`**

Replace the conversation comment line `# - database: PostgreSQL/MySQL (permanente)` with `# - database: MySQL, MariaDB, PostgreSQL ou SQLite (permanente)`.

Replace the two lines

```
# Knowledge Store: pgvector ou qdrant
AGENT_KNOWLEDGE_STORE=pgvector
AGENT_KNOWLEDGE_DB=pgsql
```

with

```
# Knowledge Store: pgvector, qdrant ou database
# - pgvector: PostgreSQL + extensão pgvector (tag agent-kit-pgvector-migrations)
# - database: qualquer banco do Laravel, ranqueado em PHP; bases pequenas (tag agent-kit-database-store-migrations)
# - qdrant: Qdrant Cloud ou self-hosted (sem migration)
AGENT_KNOWLEDGE_STORE=pgvector
# Conexão da knowledge base. pgvector: padrão pgsql. database: vazio = conexão padrão da aplicação.
AGENT_KNOWLEDGE_DB=pgsql
```

- [ ] **Step 6: Update `ARCHITECTURE.md`**

1. Replace `### 2️⃣ **KNOWLEDGE BASE (pgvector no PostgreSQL)**` with `### 2️⃣ **KNOWLEDGE BASE (pgvector, Qdrant ou banco relacional)**`.
2. After the line `**Quando usar:** Para dar contexto ao agent sobre informações estáticas`, add a blank line and:

```markdown
**Onde fica:** no pgvector (PostgreSQL), no Qdrant ou no store `database`, que guarda os embeddings numa tabela comum de MySQL, MariaDB, PostgreSQL ou SQLite e ranqueia em PHP (bases de até alguns milhares de chunks por tenant e coleção).
```

3. Replace `**Você perde os dados?** NÃO, fica permanentemente no PostgreSQL` with `**Você perde os dados?** NÃO, fica permanentemente no banco (ou no Qdrant)`.
4. Replace `**Você precisa usar Knowledge Base (pgvector)?**` with `**Você precisa usar Knowledge Base (RAG)?**`.
5. In the "Resumo Final" table, replace `| **pgvector (Knowledge)** |` with `| **Knowledge (pgvector, Qdrant ou database)** |` (rest of the row unchanged).

- [ ] **Step 7: Update `CHANGELOG.md` and `composer.json`**

In `CHANGELOG.md`, directly under `## [Não Lançado]`, add:

```markdown
### Adicionado
- Knowledge store `database` (`AGENT_KNOWLEDGE_STORE=database`): guarda os embeddings numa tabela comum, como base64 de float32 normalizado, e ranqueia por similaridade de cosseno em PHP. Funciona em MySQL 8 Community, MariaDB, PostgreSQL sem pgvector e SQLite, e é indicado para bases de até alguns milhares de chunks por tenant e coleção. A migration é publicada pela tag `agent-kit-database-store-migrations`.
- CI no GitHub Actions: suíte completa em SQLite e grupo `database` em MySQL 8.4, MariaDB 11.8 e PostgreSQL 17 com pgvector.
- Suíte de testes configurável pelas variáveis `AGENT_KIT_TEST_DB_*`, para rodar os testes do grupo `database` contra um servidor real.

### Alterado
- A migration do `knowledge_chunks` para pgvector saiu da tag `agent-kit-migrations` e passou para `agent-kit-pgvector-migrations`. O `php artisan migrate` deixa de exigir PostgreSQL com pgvector de quem usa MySQL, MariaDB, SQLite ou Qdrant. Instalações novas com pgvector publicam as duas tags; quem já publicou as migrations não precisa fazer nada.
```

In `composer.json`, replace the `keywords` line with:

```json
    "keywords": ["laravel", "ai", "agents", "openai", "anthropic", "gemini", "deepseek", "rag", "pgvector", "mysql", "mariadb", "postgresql"],
```

Run: `composer validate --no-check-publish`
Expected: `./composer.json is valid` (a warning about the lock file hash is acceptable only if it says the lock is out of date because of the keywords; then run `composer update --lock` and include `composer.lock` in the commit).

- [ ] **Step 8: Run the documentation test and the whole suite**

Run: `vendor/bin/phpunit --colors=never --no-coverage tests/Feature/Database/DatabaseDocumentationTest.php tests/Feature/Mcp/DocumentationTest.php`
Expected: OK.

Run: `grep -n '<median' README.md`
Expected: no output.

Run: `vendor/bin/phpunit --colors=never --no-coverage`
Expected: the whole suite passes.

- [ ] **Step 9: Commit**

```bash
git checkout -- .phpunit.cache/test-results
git add README.md SETUP.md ARCHITECTURE.md .env.example CHANGELOG.md composer.json tests/Feature/Database/DatabaseDocumentationTest.php
git commit -m "docs: document MySQL support, the migration tags and the database store

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```
