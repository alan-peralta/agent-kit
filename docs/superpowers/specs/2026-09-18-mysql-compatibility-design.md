# MySQL Compatibility Design (phases 1 and 2)

## Objective

Make Agent Kit usable on MySQL and MariaDB, not only PostgreSQL, without
changing behaviour for existing PostgreSQL + pgvector users.

- **Phase 1** makes the relational layer (conversation stores and analytics)
  officially supported on SQLite, MySQL, MariaDB and PostgreSQL: the pgvector
  migration no longer ships in the default publish tag, the suite can run
  against a real database server, and CI proves it on every push.
- **Phase 2** adds a portable `database` knowledge store that keeps embeddings
  in an ordinary table and ranks them in PHP, so RAG works on any database
  Laravel supports, including MySQL 8.x Community.

## Background

An assessment on 2026-09-18 established these facts with real servers
(MySQL 8.4.11, MariaDB 11.8.9):

- `DatabaseStore`, `HybridStore`, `AgentMetric` and `PersistMetricsListener`
  pass their existing tests unchanged on both engines, and the shipped
  `agent_messages` and `agent_kit_metrics` migrations run cleanly.
- `2026_05_05_000002_create_knowledge_chunks_table.php` is PostgreSQL-only
  (`CREATE EXTENSION vector`, a `vector(N)` column, an HNSW index) but is
  published by the same `agent-kit-migrations` tag as the portable migrations,
  so `php artisan migrate` fails for MySQL users even when they use Qdrant.
- MySQL Community (8.x and 9.x) has no vector distance function and no vector
  index; MySQL 8.4 has no `VECTOR` type at all. A native vector store is
  therefore impossible there; ranking must happen in PHP.
- A PHP brute-force prototype over packed float32 embeddings on MySQL 8.4
  (1536 dimensions) took about 27 ms for 280 candidates, 465 ms for 5,000 and
  1 s for 10,000 per query.
- MySQL `json` columns normalise object key order. Values stay semantically
  equal; nothing in the package depends on key order today.

## Scope

In scope: the migration layout and publish tags, a configurable test database,
database-group tests, a GitHub Actions workflow, the `database` knowledge
store, and documentation.

Out of scope:

- a MariaDB native vector store (phase 3 of the assessment);
- enabling pgvector iterative index scans in `PgvectorStore`;
- changing the default knowledge store (stays `pgvector`);
- changing the `agent_messages` or `agent_kit_metrics` schemas;
- Packagist publication.

## Phase 1: relational layer on any engine

### Migration layout and publish tags

Package migrations move into one directory per concern, because
`vendor:publish` copies a directory recursively and a subdirectory of
`database/migrations` would leak into the default tag.

| Tag | Source directory | Files |
|---|---|---|
| `agent-kit-migrations` | `database/migrations/` | `agent_messages`, `agent_kit_metrics` |
| `agent-kit-pgvector-migrations` | `database/knowledge/pgvector/` | `2026_05_05_000002_create_knowledge_chunks_table.php` (moved, unchanged) |
| `agent-kit-database-store-migrations` | `database/knowledge/database/` | `2026_09_18_000004_create_database_store_knowledge_chunks_table.php` (new, phase 2) |

All three publish into the application's `database_path('migrations')`. The
pgvector file keeps its exact name and content, so applications that already
published it see no change and a later publish of the new tag is a no-op.

Behaviour change: a fresh install that uses pgvector must now publish
`agent-kit-pgvector-migrations` in addition to `agent-kit-migrations`. This
goes into the CHANGELOG under "Alterado" and into the README upgrade notes.

### Test database selection

`tests/TestCase.php` keeps SQLite in memory as the default and reads an
optional server connection from the environment:

| Variable | Default |
|---|---|
| `AGENT_KIT_TEST_DB_CONNECTION` | `sqlite` (also `mysql`, `mariadb`, `pgsql`) |
| `AGENT_KIT_TEST_DB_HOST` | `127.0.0.1` |
| `AGENT_KIT_TEST_DB_PORT` | driver default (`3306` or `5432`) |
| `AGENT_KIT_TEST_DB_DATABASE` | `agent_kit_test` |
| `AGENT_KIT_TEST_DB_USERNAME` | `root` for MySQL/MariaDB, `postgres` for PostgreSQL |
| `AGENT_KIT_TEST_DB_PASSWORD` | empty |

MySQL and MariaDB use `utf8mb4` / `utf8mb4_unicode_ci` and strict mode, the
Laravel defaults. When a server connection is selected, the test case drops
all tables after each test, because server databases persist between tests
while the suite creates tables in `defineDatabaseMigrations()`.

Every test that touches the database carries `#[Group('database')]`. The
existing ones are `DatabaseStoreTest`, `HybridStoreTest`, `AgentMetricTest`,
`PersistMetricsListenerTest`, `AnalyticsIntegrationTest` and
`AnalyticsListenerRegistrationTest`; every new database test joins the group.

### Engine-matrix tests

New tests:

- **Publishing** (engine-agnostic, so outside the group and run by the SQLite
  job): each tag maps exactly the directory in the table above, the default tag
  contains no pgvector migration, and every migration file shipped by the
  package belongs to exactly one tag.
- **Connection** (in the group): the default connection really uses the driver
  named by `AGENT_KIT_TEST_DB_CONNECTION`, so a misconfigured run cannot pass
  silently on SQLite.
- **Core migrations** (in the group, like the rest of this list): the two core migrations run `up()` and `down()` on the
  current engine and create the expected columns.
- **Database-store migration:** runs `up()` and `down()` on the current engine.
- **pgvector:** on a `pgsql` connection where the `vector` extension is
  available, the pgvector migration runs and a `PgvectorStore` insert/search
  round trip returns the nearest chunk first. Elsewhere these tests are
  skipped with a message naming the requirement.

### CI workflow

`.github/workflows/tests.yml` runs on pushes to `main` and on pull requests:

- `tests`: PHP 8.3, `composer install` from the lock file, the whole suite on
  SQLite.
- `databases`: a matrix over `mysql:8.4`, `mariadb:11.8` and
  `pgvector/pgvector:pg17` service containers, each with a health check, running
  `vendor/bin/phpunit --group database` with the `AGENT_KIT_TEST_DB_*`
  variables pointing at the service.

PHP extensions come from `shivammathur/setup-php` (`pdo_sqlite`, `pdo_mysql`,
`pdo_pgsql`). The repository has no CI today; this is its first workflow.

## Phase 2: portable `database` knowledge store

### Configuration

A new entry in `agent-kit.knowledge.stores`, selected with
`AGENT_KNOWLEDGE_STORE=database`:

```php
'database' => [
    'driver' => 'database',
    // null = the application's default connection
    'connection' => env('AGENT_KNOWLEDGE_DB'),
    'table' => 'knowledge_chunks',
],
```

The service provider resolves the `database` driver to
`Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore`. An empty connection
string is treated as `null`.

### Schema

Migration `2026_09_18_000004_create_database_store_knowledge_chunks_table.php`
reads `agent-kit.knowledge.stores.database.connection` and `.table`:

| Column | Type |
|---|---|
| `id` | big increments |
| `tenant_id` | string(64) |
| `collection` | string(64) |
| `source` | string(255) |
| `content` | longText |
| `metadata` | json, nullable |
| `embedding` | longText |
| `created_at`, `updated_at` | timestamps |

Indexes: `tenant_id`, `(tenant_id, collection)` and `(tenant_id, source)`.
Only portable Schema Builder calls are used, so the same migration runs on
SQLite, MySQL, MariaDB and PostgreSQL, the engines CI covers. `metadata` is nullable
because MySQL rejects literal defaults on JSON columns; the store always
writes it.

### Embedding encoding

`embedding` holds base64 of the vector packed as little-endian float32
(`pack('g*')`), L2-normalised at write time.

- Text instead of a binary column: base64 round-trips identically through
  every PDO driver, while binary needs per-driver handling (pdo_pgsql returns
  `bytea` as a stream and rejects unescaped binary in string bindings).
- float32 matches the precision embedding APIs return and what pgvector stores.
- Normalising on write turns cosine similarity into a dot product at query
  time. The stored vector is never returned, so the normalisation is invisible
  to callers. A zero vector cannot be normalised and is stored as zeros, which
  gives it relevance `0.0` against any query.

### Write flow

`insertBatch()` returns immediately for an empty array. Otherwise it rejects a
chunk with an empty embedding, and a batch whose embeddings differ in
dimension, with `KnowledgeStoreException`. It then writes rows in chunks of 100
inside one transaction, so a document is indexed completely or not at all.
`insert()` delegates to `insertBatch()`. Metadata is JSON-encoded with
`JSON_UNESCAPED_UNICODE`, like `PgvectorStore`.

### Search flow

1. `limit <= 0` returns `[]`; an empty query embedding raises
   `KnowledgeStoreException`.
2. The query is L2-normalised. A zero vector yields relevance `0.0` for every
   row.
3. The store scans only `id` and `embedding` for the tenant, plus the
   collection when given, in id order and in pages of 1,000 rows
   (`lazyById`), so memory stays bounded and `content` is not transferred for
   rows that will not be returned.
4. Each embedding is decoded. A dimension different from the query raises
   `KnowledgeStoreException` naming both dimensions and telling the caller to
   re-index after changing embedders.
5. Relevance is the dot product, clamped to `[-1, 1]`, so it has the same scale
   as pgvector's `1 - cosine distance`. Candidates below `minRelevance` are
   dropped.
6. Remaining candidates are ordered by relevance descending, then id
   ascending, and the first `limit` are kept.
7. One `whereIn('id', …)` query loads their full rows. Results are returned in
   rank order as `KnowledgeChunk` with `relevance`, an integer `id` and an
   empty `embedding`. A row deleted between the scan and this query is
   skipped.

### Delete flow

`deleteBySource()` deletes rows matching `tenant_id + source` across
collections, and `deleteByTenant()` deletes every row of the tenant. Both
return the affected row count, matching `PgvectorStore`.

### Performance envelope

Search cost grows linearly with the rows of one tenant and collection. The
documentation states the envelope with numbers measured on the final
implementation (MySQL 8.4, 1536 dimensions): suitable for FAQs, policies and
manuals up to a few thousand chunks per tenant and collection; beyond that,
use pgvector or Qdrant. The store enforces no hard limit.

## Documentation

- `README.md`: supported databases, the three publish tags and when to use
  each, a knowledge store comparison (pgvector, Qdrant, database) with the
  measured envelope, and upgrade notes for pgvector installs.
- `SETUP.md`: pgvector steps marked as pgvector-only, a MySQL path, and the
  stale "the pgvector migration still runs" notes removed.
- `.env.example`: `AGENT_KNOWLEDGE_STORE` lists `database`;
  `AGENT_KNOWLEDGE_DB` explains the `null` default for the database store.
- `ARCHITECTURE.md`: the knowledge base section names the three stores.
- `CONTRIBUTING.md`: how to run the `database` group against MySQL, MariaDB
  and PostgreSQL with Docker and the `AGENT_KIT_TEST_DB_*` variables.
- `CHANGELOG.md` (pt-BR, "Não Lançado"): "Adicionado" for the store, the CI
  workflow and the configurable test database; "Alterado" for the pgvector tag.
- `composer.json` keywords gain `mysql`, `mariadb` and `postgresql`.
- A documentation test asserts that the README names the three tags and that
  `.env.example` lists the `database` store.

## Testing

- `DatabaseVectorStore` gets behaviour tests in the `database` group, so they
  run on SQLite locally and on every engine in CI: round trip, ranking order,
  tenant and collection isolation, `minRelevance`, `limit`, dimension
  mismatch, empty embeddings, mixed-dimension batch rejection, zero vector,
  paging across more than one page, transactional batch, both deletes, and
  unicode metadata.
- A binding test proves `AGENT_KNOWLEDGE_STORE=database` resolves the store
  with the configured connection and table.
- The whole suite stays green on SQLite; the `database` group is green on
  MySQL 8.4, MariaDB 11.8 and PostgreSQL 17 with pgvector, locally with Docker
  before the PR and in CI after it.
