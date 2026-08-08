# Qdrant Knowledge Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Qdrant-backed `KnowledgeStore` that supports Qdrant Cloud and self-hosted Qdrant while preserving pgvector behavior.

**Architecture:** Implement one focused `QdrantStore` over Qdrant's REST API using the package's existing Guzzle dependency. Keep one physical Qdrant collection and enforce tenant and logical-collection isolation through payload filters. Provision the collection from the first write's embedding dimension, map Qdrant UUIDs into a widened `KnowledgeChunk` ID, and expose the driver through existing Laravel configuration and container bindings.

**Tech Stack:** PHP 8.2+, Laravel 10–12 components, Guzzle 7, Orchestra Testbench, PHPUnit 10–11, Qdrant REST API.

---

## File Map

- Create `src/Exceptions/KnowledgeStoreException.php`: store-specific sanitized runtime failures.
- Create `src/Knowledge/Stores/QdrantStore.php`: configuration validation, HTTP transport, retries, provisioning, CRUD mapping, and payload filters.
- Modify `src/Knowledge/KnowledgeChunk.php`: accept integer or UUID string IDs.
- Modify `src/AgentKitServiceProvider.php`: construct `QdrantStore` when configured.
- Modify `config/agent-kit.php`: publish Qdrant driver defaults.
- Modify `.env.example`: document Cloud and self-hosted variables.
- Modify `README.md`: document selecting and using Qdrant.
- Create `phpunit.xml`: package test bootstrap and suite.
- Create `tests/TestCase.php`: Testbench package bootstrapping.
- Create `tests/Unit/Knowledge/KnowledgeChunkTest.php`: widened ID compatibility.
- Create `tests/Unit/Knowledge/QdrantStoreTest.php`: mocked REST behavior and failure cases.
- Create `tests/Feature/QdrantStoreBindingTest.php`: Laravel container resolution.
- Create `tests/Integration/QdrantStoreIntegrationTest.php`: optional live Qdrant smoke test.

The source directory is not currently a Git checkout. Run commit steps only in the actual repository/worktree; do not initialize Git implicitly in this directory.

### Task 1: Establish the package test harness and UUID-compatible chunk IDs

**Files:**
- Create: `phpunit.xml`
- Create: `tests/TestCase.php`
- Create: `tests/Unit/Knowledge/KnowledgeChunkTest.php`
- Modify: `src/Knowledge/KnowledgeChunk.php:7-17`

- [ ] **Step 1: Add PHPUnit and Testbench bootstrap files**

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Agent Kit">
            <directory>tests/Unit</directory>
            <directory>tests/Feature</directory>
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Create `tests/TestCase.php`:

```php
<?php

namespace Peralta\AgentKit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Peralta\AgentKit\AgentKitServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AgentKitServiceProvider::class];
    }
}
```

- [ ] **Step 2: Write the failing ID compatibility test**

Create `tests/Unit/Knowledge/KnowledgeChunkTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Unit\Knowledge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;

class KnowledgeChunkTest extends TestCase
{
    public static function ids(): array
    {
        return [[42], ['9d6c1a24-bad0-4f26-96c2-2fca7dbeb77e'], [null]];
    }

    #[DataProvider('ids')]
    public function test_it_accepts_database_and_qdrant_ids(int|string|null $id): void
    {
        $chunk = new KnowledgeChunk('tenant', 'faq', 'source', 'content', id: $id);

        self::assertSame($id, $chunk->id);
    }
}
```

- [ ] **Step 3: Run the focused test and verify the string case fails**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/KnowledgeChunkTest.php`

Expected: the UUID dataset fails with a `TypeError` because `$id` accepts only `?int`.

- [ ] **Step 4: Widen the public ID type**

In `src/Knowledge/KnowledgeChunk.php`, replace the last constructor property with:

```php
public readonly int|string|null $id = null,
```

- [ ] **Step 5: Run the test and commit**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/KnowledgeChunkTest.php`

Expected: `OK` with 3 tests.

```bash
git add phpunit.xml tests/TestCase.php tests/Unit/Knowledge/KnowledgeChunkTest.php src/Knowledge/KnowledgeChunk.php
git commit -m "test: establish knowledge store test harness"
```

### Task 2: Add Qdrant configuration and container resolution

**Files:**
- Create: `src/Knowledge/Stores/QdrantStore.php`
- Create: `tests/Feature/QdrantStoreBindingTest.php`
- Modify: `config/agent-kit.php:168-175`
- Modify: `src/AgentKitServiceProvider.php:16-22,119-132`

- [ ] **Step 1: Write the failing service-provider test**

Create `tests/Feature/QdrantStoreBindingTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Feature;

use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\Stores\QdrantStore;
use Peralta\AgentKit\Tests\TestCase;

class QdrantStoreBindingTest extends TestCase
{
    public function test_it_resolves_qdrant_from_package_configuration(): void
    {
        config()->set('agent-kit.knowledge.store', 'qdrant');
        config()->set('agent-kit.knowledge.stores.qdrant', [
            'driver' => 'qdrant',
            'url' => 'http://qdrant.test:6333/',
            'api_key' => 'secret',
            'collection' => 'shared_knowledge',
            'timeout' => 12,
            'batch_size' => 25,
        ]);

        self::assertInstanceOf(QdrantStore::class, app(KnowledgeStore::class));
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `vendor/bin/phpunit tests/Feature/QdrantStoreBindingTest.php`

Expected: FAIL because `QdrantStore` and the `qdrant` match arm do not exist.

- [ ] **Step 3: Add the minimal store constructor**

Create `src/Knowledge/Stores/QdrantStore.php`:

```php
<?php

namespace Peralta\AgentKit\Knowledge\Stores;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;

class QdrantStore implements KnowledgeStore
{
    protected ClientInterface $client;
    protected array $headers;

    public function __construct(
        protected string $url,
        protected ?string $apiKey = null,
        protected string $collection = 'knowledge_chunks',
        protected float $timeout = 30.0,
        protected int $batchSize = 100,
        ?ClientInterface $client = null,
    ) {
        $this->url = rtrim($url, '/');
        $this->headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $this->headers['api-key'] = $this->apiKey;
        }

        $this->client = $client ?? new Client([
            'base_uri' => $this->url . '/',
            'timeout' => $this->timeout,
            'http_errors' => false,
        ]);
    }

    public function insert(KnowledgeChunk $chunk): void {}
    public function insertBatch(array $chunks): void {}
    public function search(array $embedding, string $tenantId, ?string $collection = null, int $limit = 5, float $minRelevance = 0.0): array { return []; }
    public function deleteBySource(string $tenantId, string $source): int { return 0; }
    public function deleteByTenant(string $tenantId): int { return 0; }
}
```

- [ ] **Step 4: Add configuration and binding**

Add to `config/agent-kit.php` under `knowledge.stores`:

```php
'qdrant' => [
    'driver' => 'qdrant',
    'url' => env('QDRANT_URL', 'http://localhost:6333'),
    'api_key' => env('QDRANT_API_KEY'),
    'collection' => env('QDRANT_COLLECTION', 'knowledge_chunks'),
    'timeout' => (float) env('QDRANT_TIMEOUT', 30),
    'batch_size' => (int) env('QDRANT_BATCH_SIZE', 100),
],
```

Import `QdrantStore` in `src/AgentKitServiceProvider.php` and add this match arm after `pgvector`:

```php
'qdrant' => new QdrantStore(
    url: $cfg['url'] ?? '',
    apiKey: $cfg['api_key'] ?? null,
    collection: $cfg['collection'] ?? 'knowledge_chunks',
    timeout: (float) ($cfg['timeout'] ?? 30),
    batchSize: (int) ($cfg['batch_size'] ?? 100),
),
```

- [ ] **Step 5: Run the focused tests and commit**

Run: `vendor/bin/phpunit tests/Feature/QdrantStoreBindingTest.php tests/Unit/Knowledge/KnowledgeChunkTest.php`

Expected: all tests pass.

```bash
git add config/agent-kit.php src/AgentKitServiceProvider.php src/Knowledge/Stores/QdrantStore.php tests/Feature/QdrantStoreBindingTest.php
git commit -m "feat: register qdrant knowledge store"
```

### Task 3: Implement validated HTTP transport and automatic provisioning

**Files:**
- Create: `src/Exceptions/KnowledgeStoreException.php`
- Modify: `src/Knowledge/Stores/QdrantStore.php`
- Create: `tests/Unit/Knowledge/QdrantStoreTest.php`

- [ ] **Step 1: Add mocked HTTP tests for authentication and provisioning**

Create `tests/Unit/Knowledge/QdrantStoreTest.php` with a Guzzle `MockHandler`, `HandlerStack`, and `HistoryMiddleware`, then add these cases:

```php
public function test_it_creates_a_missing_collection_before_first_insert(): void
{
    [$store, $requests] = $this->store([
        new Response(404, [], json_encode(['status' => ['error' => 'not found']])),
        new Response(200, [], json_encode(['result' => true, 'status' => 'ok'])),
        new Response(200, [], json_encode(['result' => ['operation_id' => 1], 'status' => 'ok'])),
    ]);

    $store->insert($this->chunk([0.1, 0.2, 0.3]));

    self::assertSame('GET', $requests[0]['request']->getMethod());
    self::assertSame('/collections/knowledge_chunks', $requests[0]['request']->getUri()->getPath());
    self::assertSame([
        'vectors' => ['size' => 3, 'distance' => 'Cosine'],
    ], $this->json($requests[1]));
    self::assertSame('secret', $requests[0]['request']->getHeaderLine('api-key'));
}

public function test_it_rejects_an_existing_collection_with_another_vector_size(): void
{
    [$store] = $this->store([
        new Response(200, [], json_encode([
            'result' => ['config' => ['params' => ['vectors' => ['size' => 4, 'distance' => 'Cosine']]]],
            'status' => 'ok',
        ])),
    ]);

    $this->expectException(KnowledgeStoreException::class);
    $this->expectExceptionMessage('expects vectors with 4 dimensions; received 3');

    $store->insert($this->chunk([0.1, 0.2, 0.3]));
}
```

Use these complete helpers in the same test class:

```php
private function store(array $responses, array $overrides = []): array
{
    $mock = new MockHandler($responses);
    $history = new \ArrayObject();
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack, 'http_errors' => false]);

    $store = new QdrantStore(
        url: $overrides['url'] ?? 'http://qdrant.test:6333',
        apiKey: $overrides['api_key'] ?? 'secret',
        collection: $overrides['collection'] ?? 'knowledge_chunks',
        timeout: 1,
        batchSize: $overrides['batch_size'] ?? 100,
        client: $client,
    );

    return [$store, $history];
}

private function chunk(array $embedding = [0.1, 0.2, 0.3]): KnowledgeChunk
{
    return new KnowledgeChunk('tenant-1', 'faq', 'faq.pdf', 'Answer', ['page' => 1], $embedding);
}

private function json(array $transaction): array
{
    return json_decode((string) $transaction['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
}
```

- [ ] **Step 2: Run the provisioning tests and verify failure**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/QdrantStoreTest.php`

Expected: FAIL because the store performs no requests and `KnowledgeStoreException` does not exist.

- [ ] **Step 3: Add the exception and transport helpers**

Create `src/Exceptions/KnowledgeStoreException.php`:

```php
<?php

namespace Peralta\AgentKit\Exceptions;

class KnowledgeStoreException extends \RuntimeException {}
```

Add to `QdrantStore`:

```php
protected bool $collectionReady = false;

protected function request(string $method, string $path, array $json = []): array
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            $options = ['headers' => $this->headers];
            if ($json !== []) $options['json'] = $json;
            $response = $this->client->request($method, $this->url . '/' . ltrim($path, '/'), $options);
        } catch (\Throwable $e) {
            throw new KnowledgeStoreException("Qdrant {$method} {$path} failed: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if (($status === 429 || $status >= 500) && $attempt < 2) {
            usleep(50_000 * (2 ** $attempt));
            continue;
        }
        if ($status < 200 || $status >= 300) {
            $safe = $body;
            if ($this->apiKey !== null && $this->apiKey !== '') {
                $safe = str_replace($this->apiKey, '[redacted]', $safe);
            }
            $safe = mb_substr($safe, 0, 500);
            throw new KnowledgeStoreException("Qdrant {$method} {$path} returned HTTP {$status}: {$safe}", $status);
        }

        try {
            return $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new KnowledgeStoreException("Qdrant {$method} {$path} returned invalid JSON", 0, $e);
        }
    }

    throw new KnowledgeStoreException("Qdrant {$method} {$path} exhausted retries");
}

protected function ensureCollection(array $embedding): void
{
    if ($this->collectionReady) return;
    if ($this->url === '' || $this->collection === '' || $this->batchSize < 1 || $embedding === []) {
        throw new KnowledgeStoreException('Invalid Qdrant configuration or empty embedding.');
    }

    $path = '/collections/' . rawurlencode($this->collection);
    try {
        $response = $this->request('GET', $path);
        $size = $response['result']['config']['params']['vectors']['size'] ?? null;
        if ((int) $size !== count($embedding)) {
            throw new KnowledgeStoreException("Qdrant collection expects vectors with {$size} dimensions; received " . count($embedding));
        }
    } catch (KnowledgeStoreException $e) {
        if ($e->getCode() !== 404) throw $e;
        try {
            $this->request('PUT', $path, ['vectors' => ['size' => count($embedding), 'distance' => 'Cosine']]);
        } catch (KnowledgeStoreException $createError) {
            if (!in_array($createError->getCode(), [400, 409], true)) throw $createError;
            $this->request('GET', $path);
        }
    }
    $this->collectionReady = true;
}
```

Import `KnowledgeStoreException`. Update `insert()` temporarily to call `ensureCollection()` and then issue an empty-points upsert so the test observes all three calls; Task 4 replaces it with real mapping:

```php
public function insert(KnowledgeChunk $chunk): void
{
    $this->ensureCollection($chunk->embedding);
    $this->request('PUT', '/collections/' . rawurlencode($this->collection) . '/points?wait=true', ['points' => []]);
}
```

- [ ] **Step 4: Add and pass race, retry, no-key, invalid-JSON, and sanitization tests**

Add datasets that assert: `PUT` conflict followed by successful `GET`; two `503` responses followed by `200`; absent API key produces no `api-key` header; malformed JSON raises `KnowledgeStoreException`; a response body containing the configured key contains `[redacted]` in the exception and not the key.

Run: `vendor/bin/phpunit tests/Unit/Knowledge/QdrantStoreTest.php`

Expected: all provisioning and transport tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Exceptions/KnowledgeStoreException.php src/Knowledge/Stores/QdrantStore.php tests/Unit/Knowledge/QdrantStoreTest.php
git commit -m "feat: provision qdrant collections safely"
```

### Task 4: Implement single and batched upserts

**Files:**
- Modify: `src/Knowledge/Stores/QdrantStore.php`
- Modify: `tests/Unit/Knowledge/QdrantStoreTest.php`

- [ ] **Step 1: Write failing mapping, UUID, validation, and partition tests**

Add tests that insert 3 chunks with `batch_size=2`, supply collection-check plus two upsert responses, and assert the two request bodies contain 2 and 1 points. For every point assert a UUID-shaped `id`, the original vector, and exactly this payload:

```php
[
    'tenant_id' => 'tenant-1',
    'collection' => 'faq',
    'source' => 'faq.pdf',
    'content' => 'Answer',
    'metadata' => ['page' => 1],
]
```

Add separate tests asserting empty batches make no request and empty/mixed-size embeddings raise `KnowledgeStoreException` before an upsert.

- [ ] **Step 2: Run and verify the new tests fail**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/QdrantStoreTest.php --filter='insert|batch|embedding'`

Expected: FAIL because upserts contain no mapped points and validation is incomplete.

- [ ] **Step 3: Implement upserts and UUID generation**

Replace both write methods and add helpers:

```php
public function insert(KnowledgeChunk $chunk): void
{
    $this->insertBatch([$chunk]);
}

public function insertBatch(array $chunks): void
{
    if ($chunks === []) return;
    $dimension = count($chunks[0]->embedding);
    if ($dimension === 0) throw new KnowledgeStoreException('Qdrant cannot store an empty embedding.');
    foreach ($chunks as $chunk) {
        if (!$chunk instanceof KnowledgeChunk || count($chunk->embedding) !== $dimension) {
            throw new KnowledgeStoreException('All Qdrant batch embeddings must have the same non-zero dimension.');
        }
    }
    $this->ensureCollection($chunks[0]->embedding);
    foreach (array_chunk($chunks, $this->batchSize) as $batch) {
        $this->request('PUT', '/collections/' . rawurlencode($this->collection) . '/points?wait=true', [
            'points' => array_map(fn (KnowledgeChunk $chunk) => $this->point($chunk), $batch),
        ]);
    }
}

protected function point(KnowledgeChunk $chunk): array
{
    return [
        'id' => $chunk->id ?? $this->uuid(),
        'vector' => $chunk->embedding,
        'payload' => [
            'tenant_id' => $chunk->tenantId,
            'collection' => $chunk->collection,
            'source' => $chunk->source,
            'content' => $chunk->content,
            'metadata' => $chunk->metadata,
        ],
    ];
}

protected function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}
```

- [ ] **Step 4: Run focused and full tests, then commit**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/QdrantStoreTest.php`

Expected: all Qdrant unit tests pass.

```bash
git add src/Knowledge/Stores/QdrantStore.php tests/Unit/Knowledge/QdrantStoreTest.php
git commit -m "feat: upsert knowledge chunks into qdrant"
```

### Task 5: Implement tenant-isolated vector search

**Files:**
- Modify: `src/Knowledge/Stores/QdrantStore.php`
- Modify: `tests/Unit/Knowledge/QdrantStoreTest.php`

- [ ] **Step 1: Write failing search tests**

Add one test with a Qdrant query response containing UUID, score, and payload. Assert the request is `POST /collections/knowledge_chunks/points/query` with:

```php
[
    'query' => [0.1, 0.2, 0.3],
    'filter' => ['must' => [
        ['key' => 'tenant_id', 'match' => ['value' => 'tenant-1']],
        ['key' => 'collection', 'match' => ['value' => 'faq']],
    ]],
    'limit' => 7,
    'score_threshold' => 0.72,
    'with_payload' => true,
    'with_vector' => false,
]
```

Assert the returned `KnowledgeChunk` has the UUID ID, payload fields, metadata, empty embedding, and float relevance. Add tests for no logical collection (tenant is still mandatory), absent physical collection (`404` returns `[]`), and malformed/missing result payloads.

- [ ] **Step 2: Run and verify search tests fail**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/QdrantStoreTest.php --filter=search`

Expected: FAIL because `search()` returns an empty array without querying.

- [ ] **Step 3: Implement query and result mapping**

Replace `search()` and add filter helpers:

```php
public function search(array $embedding, string $tenantId, ?string $collection = null, int $limit = 5, float $minRelevance = 0.0): array
{
    if ($embedding === []) throw new KnowledgeStoreException('Qdrant search embedding cannot be empty.');
    $must = [$this->match('tenant_id', $tenantId)];
    if ($collection !== null) $must[] = $this->match('collection', $collection);
    try {
        $response = $this->request('POST', '/collections/' . rawurlencode($this->collection) . '/points/query', [
            'query' => $embedding,
            'filter' => ['must' => $must],
            'limit' => $limit,
            'score_threshold' => $minRelevance,
            'with_payload' => true,
            'with_vector' => false,
        ]);
    } catch (KnowledgeStoreException $e) {
        if ($e->getCode() === 404) return [];
        throw $e;
    }
    $points = $response['result']['points'] ?? $response['result'] ?? null;
    if (!is_array($points)) throw new KnowledgeStoreException('Qdrant query response is missing result points.');
    return array_map(fn (array $point) => $this->toChunk($point), $points);
}

protected function match(string $key, string $value): array
{
    return ['key' => $key, 'match' => ['value' => $value]];
}

protected function toChunk(array $point): KnowledgeChunk
{
    $payload = $point['payload'] ?? [];
    foreach (['tenant_id', 'collection', 'source', 'content'] as $key) {
        if (!array_key_exists($key, $payload)) throw new KnowledgeStoreException("Qdrant point payload is missing {$key}.");
    }
    return new KnowledgeChunk(
        tenantId: (string) $payload['tenant_id'],
        collection: (string) $payload['collection'],
        source: (string) $payload['source'],
        content: (string) $payload['content'],
        metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        embedding: [],
        relevance: isset($point['score']) ? (float) $point['score'] : null,
        id: $point['id'] ?? null,
    );
}
```

- [ ] **Step 4: Run tests and commit**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/QdrantStoreTest.php`

Expected: all Qdrant store tests pass.

```bash
git add src/Knowledge/Stores/QdrantStore.php tests/Unit/Knowledge/QdrantStoreTest.php
git commit -m "feat: search qdrant with tenant filters"
```

### Task 6: Implement filtered deletion with matched counts

**Files:**
- Modify: `src/Knowledge/Stores/QdrantStore.php`
- Modify: `tests/Unit/Knowledge/QdrantStoreTest.php`

- [ ] **Step 1: Write failing deletion tests**

For both deletion methods, enqueue a count response and delete response. Assert `deleteBySource()` uses tenant and source matches, `deleteByTenant()` uses tenant only, both return `result.count`, and delete sends:

```php
[
    'filter' => ['must' => $must],
]
```

to `POST /collections/knowledge_chunks/points/delete?wait=true`. Add a `404` count test that returns `0` and does not issue delete.

- [ ] **Step 2: Run and verify deletion tests fail**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/QdrantStoreTest.php --filter=delete`

Expected: FAIL because both methods return `0` without requests.

- [ ] **Step 3: Implement count-before-delete**

Replace both public methods and add a helper:

```php
public function deleteBySource(string $tenantId, string $source): int
{
    return $this->deleteByFilter([$this->match('tenant_id', $tenantId), $this->match('source', $source)]);
}

public function deleteByTenant(string $tenantId): int
{
    return $this->deleteByFilter([$this->match('tenant_id', $tenantId)]);
}

protected function deleteByFilter(array $must): int
{
    $base = '/collections/' . rawurlencode($this->collection) . '/points';
    try {
        $count = $this->request('POST', $base . '/count', ['filter' => ['must' => $must], 'exact' => true]);
    } catch (KnowledgeStoreException $e) {
        if ($e->getCode() === 404) return 0;
        throw $e;
    }
    $matched = (int) ($count['result']['count'] ?? 0);
    if ($matched === 0) return 0;
    $this->request('POST', $base . '/delete?wait=true', ['filter' => ['must' => $must]]);
    return $matched;
}
```

- [ ] **Step 4: Run tests and commit**

Run: `vendor/bin/phpunit tests/Unit/Knowledge/QdrantStoreTest.php`

Expected: all tests pass, including tenant filters on every delete request.

```bash
git add src/Knowledge/Stores/QdrantStore.php tests/Unit/Knowledge/QdrantStoreTest.php
git commit -m "feat: delete qdrant knowledge by tenant filters"
```

### Task 7: Document usage and add an opt-in integration smoke test

**Files:**
- Modify: `.env.example`
- Modify: `README.md`
- Create: `tests/Integration/QdrantStoreIntegrationTest.php`

- [ ] **Step 1: Add Qdrant environment documentation**

Replace the Knowledge Store block in `.env.example` with:

```env
# Knowledge Store: pgvector ou qdrant
AGENT_KNOWLEDGE_STORE=pgvector
AGENT_KNOWLEDGE_DB=pgsql

# Qdrant Cloud ou self-hosted (usado quando AGENT_KNOWLEDGE_STORE=qdrant)
QDRANT_URL=http://localhost:6333
QDRANT_API_KEY=
QDRANT_COLLECTION=knowledge_chunks
QDRANT_TIMEOUT=30
QDRANT_BATCH_SIZE=100
```

- [ ] **Step 2: Add README configuration and behavior notes**

Under `## RAG (Knowledge Base)`, add:

````markdown
### Qdrant

Use Qdrant Cloud or a self-hosted Qdrant instance without changing the indexing
or agent APIs:

```env
AGENT_KNOWLEDGE_STORE=qdrant
QDRANT_URL=https://your-cluster.cloud.qdrant.io:6333
QDRANT_API_KEY=your-api-key
QDRANT_COLLECTION=knowledge_chunks
```

For an unsecured local instance, leave `QDRANT_API_KEY` empty. The package
creates the physical collection on the first write using the embedding
dimension and cosine distance. One physical collection is shared; tenant and
logical collection isolation are enforced through payload filters.
````

- [ ] **Step 3: Add the opt-in live smoke test**

Create `tests/Integration/QdrantStoreIntegrationTest.php`:

```php
<?php

namespace Peralta\AgentKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\QdrantStore;

class QdrantStoreIntegrationTest extends TestCase
{
    public function test_live_qdrant_round_trip_when_configured(): void
    {
        $url = getenv('QDRANT_TEST_URL');
        if (!$url) self::markTestSkipped('Set QDRANT_TEST_URL to run live Qdrant tests.');
        $tenant = 'agent-kit-test-' . bin2hex(random_bytes(6));
        $store = new QdrantStore(
            url: $url,
            apiKey: getenv('QDRANT_TEST_API_KEY') ?: null,
            collection: getenv('QDRANT_TEST_COLLECTION') ?: 'agent_kit_tests',
        );
        try {
            $store->insert(new KnowledgeChunk($tenant, 'faq', 'smoke', 'Qdrant works', [], [1.0, 0.0, 0.0]));
            $results = $store->search([1.0, 0.0, 0.0], $tenant, 'faq', 1, 0.9);
            self::assertCount(1, $results);
            self::assertSame('Qdrant works', $results[0]->content);
        } finally {
            $store->deleteByTenant($tenant);
        }
    }
}
```

- [ ] **Step 4: Run the complete offline suite**

Run: `vendor/bin/phpunit`

Expected: all unit and feature tests pass; the live integration test is skipped unless `QDRANT_TEST_URL` is set.

- [ ] **Step 5: Run static syntax validation**

Run: `find src tests config database -name '*.php' -print0 | xargs -0 -n1 php -l`

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 6: Commit documentation and integration coverage**

```bash
git add .env.example README.md tests/Integration/QdrantStoreIntegrationTest.php
git commit -m "docs: document qdrant knowledge storage"
```

### Task 8: Final regression and requirement verification

**Files:**
- Verify: all files changed in Tasks 1–7

- [ ] **Step 1: Confirm the package dependency graph is unchanged**

Run: `composer validate --strict`

Expected: `composer.json is valid`; no Qdrant SDK is present in `require`.

- [ ] **Step 2: Run the complete test suite without network access**

Run: `vendor/bin/phpunit --testdox`

Expected: all unit and feature cases pass and the live Qdrant case is skipped.

- [ ] **Step 3: Check documented variables match published configuration**

Run: `rg 'QDRANT_(URL|API_KEY|COLLECTION|TIMEOUT|BATCH_SIZE)' .env.example config/agent-kit.php README.md`

Expected: all five variables appear in `.env.example` and config; README includes the required connection variables.

- [ ] **Step 4: Inspect the final diff for accidental scope growth**

Run: `git diff --check && git status --short`

Expected: no whitespace errors; only Qdrant store, tests, configuration, ID compatibility, exception, and documentation files are changed.

- [ ] **Step 5: Create the final implementation commit if earlier commits were unavailable**

```bash
git add .env.example README.md config/agent-kit.php phpunit.xml src tests
git commit -m "feat: add qdrant knowledge store"
```
