<?php

namespace Peralta\AgentKit\Tests\Unit\Knowledge;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use Peralta\AgentKit\Exceptions\KnowledgeStoreException;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use Peralta\AgentKit\Knowledge\Stores\QdrantStore;
use PHPUnit\Framework\TestCase;

class QdrantStoreTest extends TestCase
{
    public function test_it_creates_collection_then_inserts_using_absolute_urls_and_api_key(): void
    {
        [$client, $history] = $this->client([
            new Response(404, [], '{"status":"not found"}'),
            new Response(200, [], '{"result":true}'),
            new Response(200, [], '{"result":true}'),
        ]);

        $store = new QdrantStore('http://qdrant.test:6333///', 'secret', 'my collection', client: $client, sleeper: static fn () => null);
        $store->insert($this->chunk([0.1, 0.2, 0.3]));

        self::assertSame([
            'GET http://qdrant.test:6333/collections/my%20collection',
            'PUT http://qdrant.test:6333/collections/my%20collection',
            'PUT http://qdrant.test:6333/collections/my%20collection/points?wait=true',
        ], array_map(fn ($entry) => $entry['request']->getMethod().' '.(string) $entry['request']->getUri(), (array)$history));
        self::assertSame(['vectors' => ['size' => 3, 'distance' => 'Cosine']], json_decode((string) $history[1]['request']->getBody(), true));
        $body = json_decode((string) $history[2]['request']->getBody(), true);
        self::assertCount(1, $body['points']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $body['points'][0]['id'],
        );
        self::assertSame([0.1, 0.2, 0.3], $body['points'][0]['vector']);
        self::assertSame([
            'tenant_id' => 'tenant',
            'collection' => 'docs',
            'source' => 'source',
            'content' => 'content',
            'metadata' => [],
        ], $body['points'][0]['payload']);
        foreach ($history as $entry) {
            self::assertSame('secret', $entry['request']->getHeaderLine('api-key'));
            self::assertSame('application/json', $entry['request']->getHeaderLine('Accept'));
            self::assertSame('application/json', $entry['request']->getHeaderLine('Content-Type'));
        }
    }

    public function test_it_rejects_existing_collection_with_incompatible_dimension(): void
    {
        [$client] = $this->client([new Response(200, [], $this->collection(4))]);
        $store = new QdrantStore('http://qdrant.test', collection: 'docs', client: $client, sleeper: static fn () => null);

        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('dimension 4');
        $store->insert($this->chunk([1, 2, 3]));
    }

    public function test_it_omits_api_key_header_when_key_is_null_or_empty(): void
    {
        foreach ([null, ''] as $key) {
            [$client, $history] = $this->client([new Response(200, [], $this->collection(3)), new Response(200, [], '{}')]);
            (new QdrantStore('http://qdrant.test', $key, client: $client, sleeper: static fn () => null))->insert($this->chunk([1, 2, 3]));
            self::assertFalse($history[0]['request']->hasHeader('api-key'));
        }
    }

    /** @dataProvider absentApiKeys */
    public function test_it_removes_inherited_authentication_headers_when_store_has_no_key(?string $apiKey): void
    {
        [$client, $history] = $this->client(
            [new Response(200, [], $this->collection(1)), new Response(200, [], '{}')],
            ['api-key' => 'stale-secret', 'Authorization' => 'Bearer stale'],
        );

        (new QdrantStore('http://qdrant.test', $apiKey, client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));

        foreach ($history as $entry) {
            self::assertFalse($entry['request']->hasHeader('api-key'));
            self::assertFalse($entry['request']->hasHeader('Authorization'));
        }
    }

    public function test_it_replaces_inherited_api_key_and_removes_inherited_authorization(): void
    {
        [$client, $history] = $this->client(
            [new Response(200, [], $this->collection(1)), new Response(200, [], '{}')],
            ['api-key' => 'stale-secret', 'Authorization' => 'Bearer stale'],
        );

        (new QdrantStore('http://qdrant.test', 'current-secret', client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));

        foreach ($history as $entry) {
            self::assertSame('current-secret', $entry['request']->getHeaderLine('api-key'));
            self::assertFalse($entry['request']->hasHeader('Authorization'));
        }
    }

    public function test_it_retries_retryable_responses_at_most_twice(): void
    {
        [$client, $history] = $this->client([
            new Response(503, [], 'busy'), new Response(503, [], 'busy'),
            new Response(200, [], $this->collection(3)), new Response(200, [], '{}'),
        ]);
        (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1, 2, 3]));
        self::assertCount(4, $history);
    }

    public function test_it_honors_retry_after_with_a_one_second_cap(): void
    {
        [$client] = $this->client([
            new Response(429, ['Retry-After' => '20'], 'slow down'),
            new Response(200, [], $this->collection(1)),
            new Response(200, [], '{}'),
        ]);
        $delays = [];

        (new QdrantStore('http://qdrant.test', client: $client, sleeper: function (int $microseconds) use (&$delays): void {
            $delays[] = $microseconds;
        }))->insert($this->chunk([1]));

        self::assertSame([1_000_000], $delays);
    }

    public function test_it_honors_http_date_retry_after_with_a_one_second_cap(): void
    {
        [$client] = $this->client([
            new Response(503, ['Retry-After' => gmdate(DATE_RFC7231, time() + 60)], 'busy'),
            new Response(200, [], $this->collection(1)), new Response(200, [], '{}'),
        ]);
        $delays = [];

        (new QdrantStore('http://qdrant.test', client: $client, sleeper: function (int $delay) use (&$delays): void {
            $delays[] = $delay;
        }))->insert($this->chunk([1]));

        self::assertSame([1_000_000], $delays);
    }

    public function test_it_stops_after_three_retryable_failures(): void
    {
        [$client, $history] = $this->client([
            new Response(503, [], 'busy'), new Response(503, [], 'busy'), new Response(503, [], 'still busy'),
        ]);

        try {
            (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            self::assertSame(503, $exception->getCode());
            self::assertCount(3, $history);
        }
    }

    public function test_transport_exception_does_not_retain_a_secret_previous_exception(): void
    {
        $secret = 'transport-secret';
        [$client] = $this->client([
            new ConnectException('Connection failed using '.$secret, new Request('GET', 'http://qdrant.test')),
        ]);

        try {
            (new QdrantStore('http://qdrant.test', $secret, client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
                self::assertStringNotContainsString($secret, $current->getMessage());
            }
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString('[redacted]', $exception->getMessage());
        }
    }

    public function test_it_does_not_retry_non_404_client_errors_and_preserves_status_code(): void
    {
        [$client, $history] = $this->client([new Response(401, [], 'denied')]);
        try {
            (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            self::assertSame(401, $exception->getCode());
            self::assertCount(1, $history);
        }
    }

    public function test_it_does_not_retry_a_regular_400_response(): void
    {
        [$client, $history] = $this->client([new Response(400, [], 'invalid request')]);

        try {
            (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            self::assertSame(400, $exception->getCode());
            self::assertCount(1, $history);
        }
    }

    public function test_it_rejects_invalid_json(): void
    {
        [$client] = $this->client([new Response(200, [], '{invalid')]);
        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('invalid JSON');
        (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
    }

    public function test_it_redacts_api_key_from_error_body(): void
    {
        [$client] = $this->client([new Response(500, [], 'secret-token'), new Response(500, [], 'secret-token'), new Response(500, [], 'secret-token')]);
        try {
            (new QdrantStore('http://qdrant.test', 'secret-token', client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            self::assertStringContainsString('[redacted]', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
        }
    }

    public function test_it_redacts_a_long_api_key_crossing_the_500_character_boundary_before_truncating(): void
    {
        $apiKey = str_repeat('sensitive-key-', 25);
        $body = str_repeat('x', 450).$apiKey.' trailing details';
        [$client] = $this->client([new Response(400, [], $body)]);

        try {
            (new QdrantStore('http://qdrant.test', $apiKey, client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            $sanitizedBody = $this->errorBody($exception);
            self::assertLessThanOrEqual(500, strlen($sanitizedBody));
            self::assertStringContainsString('[redacted]', $sanitizedBody);
            self::assertStringNotContainsString($apiKey, $sanitizedBody);
            self::assertStringNotContainsString('sensitive-key-sensitive-key-', $sanitizedBody);
        }
    }

    public function test_it_limits_the_final_body_after_replacing_repeated_short_api_keys(): void
    {
        $apiKey = 'key';
        $body = str_repeat($apiKey, 300);
        [$client] = $this->client([new Response(400, [], $body)]);

        try {
            (new QdrantStore('http://qdrant.test', $apiKey, client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            $sanitizedBody = $this->errorBody($exception);
            self::assertLessThanOrEqual(500, strlen($sanitizedBody));
            self::assertStringContainsString('[redacted]', $sanitizedBody);
            self::assertStringNotContainsString($apiKey, $sanitizedBody);
        }
    }

    public function test_it_does_not_split_a_utf8_character_at_the_500_byte_boundary(): void
    {
        [$client] = $this->client([new Response(400, [], str_repeat('a', 499).'😀tail')]);

        try {
            (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            $sanitizedBody = $this->errorBody($exception);
            self::assertLessThanOrEqual(500, strlen($sanitizedBody));
            self::assertSame(1, preg_match('//u', $sanitizedBody));
            self::assertSame(str_repeat('a', 499), $sanitizedBody);
        }
    }

    /** @dataProvider absentApiKeys */
    public function test_error_sanitization_handles_absent_api_keys(?string $apiKey): void
    {
        [$client] = $this->client([new Response(400, [], 'ordinary error')]);

        try {
            (new QdrantStore('http://qdrant.test', $apiKey, client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            self::assertStringContainsString('ordinary error', $exception->getMessage());
        }
    }

    public static function absentApiKeys(): array
    {
        return [[null], ['']];
    }

    /** @dataProvider raceStatuses */
    public function test_it_recovers_from_collection_creation_race(int $status): void
    {
        [$client, $history] = $this->client([
            new Response(404, [], '{}'), new Response($status, [], 'already exists'),
            new Response(200, [], $this->collection(3)), new Response(200, [], '{}'),
        ]);
        (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1, 2, 3]));
        self::assertSame(['GET', 'PUT', 'GET', 'PUT'], array_map(fn ($entry) => $entry['request']->getMethod(), (array)$history));
        self::assertSame(['vectors' => ['size' => 3, 'distance' => 'Cosine']], json_decode((string) $history[1]['request']->getBody(), true));
    }

    public static function raceStatuses(): array
    {
        return [[400], [409]];
    }

    public function test_it_rejects_incompatible_collection_after_creation_race(): void
    {
        [$client] = $this->client([new Response(404, [], '{}'), new Response(409, [], 'exists'), new Response(200, [], $this->collection(2))]);
        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('dimension 2');
        (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1, 2, 3]));
    }

    public function test_creation_race_retries_temporary_404_until_collection_is_visible(): void
    {
        [$client, $history] = $this->client([
            new Response(404, [], '{}'), new Response(409, [], 'already exists'),
            new Response(404, [], 'not visible'), new Response(200, [], $this->collection(3)),
            new Response(200, [], '{}'),
        ]);
        $delays = [];

        (new QdrantStore('http://qdrant.test', client: $client, sleeper: function (int $microseconds) use (&$delays): void {
            $delays[] = $microseconds;
        }))->insert($this->chunk([1, 2, 3]));

        self::assertSame(['GET', 'PUT', 'GET', 'GET', 'PUT'], array_map(fn ($entry) => $entry['request']->getMethod(), $history));
        self::assertSame([50_000], $delays);
    }

    public function test_creation_race_fails_after_three_missing_confirmations(): void
    {
        [$client, $history] = $this->client([
            new Response(404, [], '{}'), new Response(409, [], 'already exists'),
            new Response(404, [], 'missing one'), new Response(404, [], 'missing two'), new Response(404, [], 'missing three'),
        ]);

        try {
            (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk([1]));
            self::fail('Expected exception.');
        } catch (KnowledgeStoreException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('could not confirm', $exception->getMessage());
            self::assertCount(5, $history);
        }
    }

    /** @dataProvider invalidEmbeddings */
    public function test_it_rejects_non_dense_or_non_finite_embeddings(array $embedding): void
    {
        [$client, $history] = $this->client([]);
        $this->expectException(KnowledgeStoreException::class);
        try {
            (new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null))->insert($this->chunk($embedding));
        } finally {
            self::assertCount(0, $history);
        }
    }

    public static function invalidEmbeddings(): array
    {
        return [[[0 => 1.0, 2 => 2.0]], [['1.0']], [[INF]], [[NAN]]];
    }

    /** @dataProvider invalidConfiguration */
    public function test_it_validates_configuration_before_transport(string $url, string $collection, int $batchSize, array $embedding, string $message): void
    {
        [$client, $history] = $this->client([]);
        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage($message);
        try {
            (new QdrantStore($url, collection: $collection, batchSize: $batchSize, client: $client, sleeper: static fn () => null))->insert($this->chunk($embedding));
        } finally {
            self::assertCount(0, $history);
        }
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['', 'docs', 1, [1], 'URL'],
            ['http://qdrant.test', '', 1, [1], 'collection'],
            ['http://qdrant.test', 'docs', 0, [1], 'batch size'],
            ['http://qdrant.test', 'docs', 1, [], 'embedding'],
        ];
    }

    public function test_it_caches_successful_collection_validation(): void
    {
        [$client, $history] = $this->client([new Response(200, [], $this->collection(3)), new Response(200, [], '{}'), new Response(200, [], '{}')]);
        $store = new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null);
        $store->insert($this->chunk([1, 2, 3]));
        $store->insert($this->chunk([4, 5, 6]));
        self::assertSame(['GET', 'PUT', 'PUT'], array_map(fn ($entry) => $entry['request']->getMethod(), $history));
    }

    public function test_insert_batch_partitions_points_and_preserves_ids_and_metadata(): void
    {
        [$client, $history] = $this->client([
            new Response(200, [], $this->collection(2)),
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
        ]);
        $chunks = [
            new KnowledgeChunk('t1', 'articles', 's1', 'Olá 😀', ['nested' => ['ação' => true]], [1, 2], id: 42),
            new KnowledgeChunk('t2', 'articles', 's2', 'second', ['tags' => ['α', 'β']], [3, 4], id: '123e4567-e89b-12d3-a456-426614174000'),
            new KnowledgeChunk('t3', 'articles', 's3', 'third', [], [5, 6]),
        ];

        (new QdrantStore('http://qdrant.test', collection: 'my docs', batchSize: 2, client: $client, sleeper: static fn () => null))
            ->insertBatch($chunks);

        self::assertCount(3, $history);
        self::assertSame('GET http://qdrant.test/collections/my%20docs', $history[0]['request']->getMethod().' '.$history[0]['request']->getUri());
        foreach ([1, 2] as $index) {
            self::assertSame('PUT', $history[$index]['request']->getMethod());
            self::assertSame('/collections/my%20docs/points', $history[$index]['request']->getUri()->getPath());
            self::assertSame('wait=true', $history[$index]['request']->getUri()->getQuery());
        }
        $first = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode((string) $history[2]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $first['points']);
        self::assertCount(1, $second['points']);
        self::assertSame(42, $first['points'][0]['id']);
        self::assertSame('123e4567-e89b-12d3-a456-426614174000', $first['points'][1]['id']);
        self::assertSame([1, 2], $first['points'][0]['vector']);
        self::assertSame([3, 4], $first['points'][1]['vector']);
        self::assertSame([5, 6], $second['points'][0]['vector']);
        self::assertSame([
            'tenant_id' => 't1', 'collection' => 'articles', 'source' => 's1',
            'content' => 'Olá 😀', 'metadata' => ['nested' => ['ação' => true]],
        ], $first['points'][0]['payload']);
        self::assertSame([
            'tenant_id' => 't2', 'collection' => 'articles', 'source' => 's2',
            'content' => 'second', 'metadata' => ['tags' => ['α', 'β']],
        ], $first['points'][1]['payload']);
        self::assertSame([
            'tenant_id' => 't3', 'collection' => 'articles', 'source' => 's3',
            'content' => 'third', 'metadata' => [],
        ], $second['points'][0]['payload']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $second['points'][0]['id']);
    }

    public function test_generated_ids_are_unique_uuid_v4_values(): void
    {
        [$client, $history] = $this->client([new Response(200, [], $this->collection(1)), new Response(200, [], '{}')]);
        $store = new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null);
        $store->insertBatch([$this->chunk([1]), $this->chunk([2])]);
        $points = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)['points'];

        self::assertNotSame($points[0]['id'], $points[1]['id']);
        foreach ($points as $point) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $point['id']);
        }
    }

    public function test_search_uses_query_api_with_exact_filters_options_and_authentication(): void
    {
        [$client, $history] = $this->client([new Response(200, [], '{"result":{"points":[]}}')]);

        $result = (new QdrantStore('http://qdrant.test/', 'secret', 'my docs', client: $client))
            ->search([0.25, 2], 'tenant-a', 'articles', 7, 0.65);

        self::assertSame([], $result);
        self::assertCount(1, $history);
        self::assertSame('POST http://qdrant.test/collections/my%20docs/points/query', $history[0]['request']->getMethod().' '.$history[0]['request']->getUri());
        self::assertSame('secret', $history[0]['request']->getHeaderLine('api-key'));
        self::assertFalse($history[0]['request']->hasHeader('Authorization'));
        self::assertSame([
            'query' => [0.25, 2],
            'filter' => ['must' => [
                ['key' => 'tenant_id', 'match' => ['value' => 'tenant-a']],
                ['key' => 'collection', 'match' => ['value' => 'articles']],
            ]],
            'limit' => 7,
            'score_threshold' => 0.65,
            'with_payload' => true,
            'with_vector' => false,
        ], json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_search_without_collection_filters_only_by_tenant(): void
    {
        [$client, $history] = $this->client([new Response(200, [], '{"result":{"points":[]}}')]);
        (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant', null);

        self::assertSame([
            ['key' => 'tenant_id', 'match' => ['value' => 'tenant']],
        ], json_decode((string) $history[0]['request']->getBody(), true)['filter']['must']);
    }

    public function test_search_maps_current_and_legacy_results_with_integer_and_uuid_ids(): void
    {
        $points = [
            ['id' => 0, 'score' => 1, 'payload' => [
                'tenant_id' => 'tenant', 'collection' => 'docs', 'source' => 'one',
                'content' => 'Olá 😀', 'metadata' => ['nested' => ['ação' => '✓']],
            ]],
            ['id' => '123e4567-e89b-12d3-a456-426614174000', 'score' => 0.75, 'payload' => [
                'tenant_id' => 'tenant', 'collection' => 'docs', 'source' => 'two',
                'content' => 'content', 'metadata' => 'ignored',
            ]],
        ];

        foreach ([['result' => ['points' => $points]], ['result' => $points]] as $response) {
            [$client] = $this->client([new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR))]);
            $chunks = (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant');

            self::assertCount(2, $chunks);
            self::assertSame(0, $chunks[0]->id);
            self::assertSame([], $chunks[0]->embedding);
            self::assertSame(1.0, $chunks[0]->relevance);
            self::assertSame(['nested' => ['ação' => '✓']], $chunks[0]->metadata);
            self::assertSame('123e4567-e89b-12d3-a456-426614174000', $chunks[1]->id);
            self::assertSame([], $chunks[1]->metadata);
        }
    }

    public function test_search_returns_empty_for_missing_collection_without_retry_or_provisioning(): void
    {
        [$client, $history] = $this->client([new Response(404, [], 'missing')]);
        self::assertSame([], (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant'));
        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
    }

    /** @dataProvider invalidSearchArguments */
    public function test_search_rejects_invalid_arguments_before_transport(array $embedding, string $tenant, ?string $collection, int $limit, float $relevance): void
    {
        [$client, $history] = $this->client([]);
        $this->expectException(KnowledgeStoreException::class);
        try {
            (new QdrantStore('http://qdrant.test', client: $client))->search($embedding, $tenant, $collection, $limit, $relevance);
        } finally {
            self::assertCount(0, $history);
        }
    }

    public static function invalidSearchArguments(): array
    {
        return [
            'empty embedding' => [[], 'tenant', null, 1, 0.0],
            'sparse embedding' => [[0 => 1, 2 => 2], 'tenant', null, 1, 0.0],
            'non numeric embedding' => [['1'], 'tenant', null, 1, 0.0],
            'empty tenant' => [[1], '  ', null, 1, 0.0],
            'empty collection' => [[1], 'tenant', ' ', 1, 0.0],
            'zero limit' => [[1], 'tenant', null, 0, 0.0],
            'infinite relevance' => [[1], 'tenant', null, 1, INF],
            'nan relevance' => [[1], 'tenant', null, 1, NAN],
        ];
    }

    /** @dataProvider malformedSearchResponses */
    public function test_search_rejects_malformed_responses(array|string $response): void
    {
        $body = is_string($response) ? $response : json_encode($response, JSON_THROW_ON_ERROR);
        [$client] = $this->client([new Response(200, [], $body)]);
        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('Qdrant search response');
        (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant');
    }

    public static function malformedSearchResponses(): array
    {
        $validPayload = ['tenant_id' => 'tenant', 'collection' => 'docs', 'source' => 'source', 'content' => 'content'];

        return [
            'missing result' => [[]],
            'empty result object' => ['{"result":{}}'],
            'empty points object' => ['{"result":{"points":{}}}'],
            'associative result envelope without points' => [['result' => ['status' => 'ok']]],
            'points not list' => [['result' => ['points' => ['x' => []]]]],
            'point not object' => [['result' => ['points' => ['bad']]]],
            'missing id' => [['result' => ['points' => [['score' => 1, 'payload' => $validPayload]]]]],
            'negative id' => [['result' => ['points' => [['id' => -1, 'score' => 1, 'payload' => $validPayload]]]]],
            'invalid uuid' => [['result' => ['points' => [['id' => 'id', 'score' => 1, 'payload' => $validPayload]]]]],
            'non numeric score' => [['result' => ['points' => [['id' => 1, 'score' => '1', 'payload' => $validPayload]]]]],
            'infinite score' => ['{"result":{"points":[{"id":1,"score":1e999,"payload":{"tenant_id":"tenant","collection":"docs","source":"source","content":"content"}}]}}'],
            'payload not object' => [['result' => ['points' => [['id' => 1, 'score' => 1, 'payload' => 'bad']]]]],
            'missing payload field' => [['result' => ['points' => [['id' => 1, 'score' => 1, 'payload' => ['tenant_id' => 'tenant']]]]]],
            'wrong payload field type' => [['result' => ['points' => [['id' => 1, 'score' => 1, 'payload' => array_merge($validPayload, ['content' => 3])]]]]],
        ];
    }

    public function test_search_rejects_a_top_level_json_list(): void
    {
        [$client] = $this->client([new Response(200, [], '[]')]);

        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('invalid JSON');
        (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant');
    }

    public function test_search_rejects_a_cross_tenant_result(): void
    {
        $response = ['result' => ['points' => [[
            'id' => 1, 'score' => 1, 'payload' => [
                'tenant_id' => 'other', 'collection' => 'docs', 'source' => 'source', 'content' => 'content',
            ],
        ]]]];
        [$client] = $this->client([new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR))]);

        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('tenant');
        (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant');
    }

    public function test_search_normalizes_empty_json_objects_recursively_in_metadata(): void
    {
        $body = '{"result":{"points":[{"id":1,"score":0.9,"payload":{"tenant_id":"tenant","collection":"docs","source":"source","content":"content","metadata":{"nested":{},"list":[{}]}}}]}}';
        [$client] = $this->client([new Response(200, [], $body)]);

        $chunks = (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant');

        self::assertSame(['nested' => [], 'list' => [[]]], $chunks[0]->metadata);
    }

    public function test_search_rejects_a_result_from_a_different_requested_collection(): void
    {
        $response = ['result' => ['points' => [[
            'id' => 1, 'score' => 1, 'payload' => [
                'tenant_id' => 'tenant', 'collection' => 'other', 'source' => 'source', 'content' => 'content',
            ],
        ]]]];
        [$client] = $this->client([new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR))]);

        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('collection isolation');
        (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant', 'docs');
    }

    public function test_search_without_collection_accepts_any_valid_payload_collection(): void
    {
        $response = ['result' => ['points' => [[
            'id' => 1, 'score' => 1, 'payload' => [
                'tenant_id' => 'tenant', 'collection' => 'other', 'source' => 'source', 'content' => 'content',
            ],
        ]]]];
        [$client] = $this->client([new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR))]);

        $chunks = (new QdrantStore('http://qdrant.test', client: $client))->search([1], 'tenant');
        self::assertSame('other', $chunks[0]->collection);
    }

    public function test_batch_propagates_second_upsert_failure_after_first_batch_was_sent(): void
    {
        [$client, $history] = $this->client([
            new Response(200, [], $this->collection(1)),
            new Response(200, [], '{}'),
            new Response(400, [], 'invalid second batch'),
        ]);
        $store = new QdrantStore(
            'http://qdrant.test',
            batchSize: 2,
            client: $client,
            sleeper: static fn () => null,
        );

        try {
            $store->insertBatch([$this->chunk([1]), $this->chunk([2]), $this->chunk([3])]);
            self::fail('Expected the second upsert to fail.');
        } catch (KnowledgeStoreException $exception) {
            self::assertSame(400, $exception->getCode());
            self::assertCount(3, $history);
            self::assertSame(['GET', 'PUT', 'PUT'], array_map(
                fn ($entry) => $entry['request']->getMethod(),
                $history,
            ));
            self::assertCount(2, json_decode(
                (string) $history[1]['request']->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            )['points']);
            self::assertCount(1, json_decode(
                (string) $history[2]['request']->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            )['points']);
        }
    }

    public function test_empty_batch_makes_no_requests(): void
    {
        [$client, $history] = $this->client([]);
        (new QdrantStore('http://qdrant.test', client: $client))->insertBatch([]);
        self::assertCount(0, $history);
    }

    /** @dataProvider invalidPointIds */
    public function test_batch_rejects_invalid_point_ids_before_any_request(int|string $id): void
    {
        [$client, $history] = $this->client([]);
        $this->expectException(KnowledgeStoreException::class);
        $this->expectExceptionMessage('ID');
        try {
            (new QdrantStore('http://qdrant.test', client: $client))->insertBatch([
                new KnowledgeChunk('tenant', 'docs', 'source', 'content', embedding: [1], id: $id),
            ]);
        } finally {
            self::assertCount(0, $history);
        }
    }

    public static function invalidPointIds(): array
    {
        return ['negative integer' => [-1], 'arbitrary string' => ['point-id']];
    }

    /** @dataProvider unserializableChunks */
    public function test_batch_rejects_unserializable_points_before_any_request(KnowledgeChunk $chunk): void
    {
        [$client, $history] = $this->client([]);
        try {
            (new QdrantStore('http://qdrant.test', client: $client))->insertBatch([$chunk]);
            self::fail('Expected serialization failure.');
        } catch (KnowledgeStoreException $exception) {
            self::assertStringContainsString('serializable', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertCount(0, $history);
        }
    }

    public static function unserializableChunks(): array
    {
        $resource = fopen('php://memory', 'r');

        return [
            'metadata NAN' => [new KnowledgeChunk('tenant', 'docs', 'source', 'content', ['bad' => NAN], [1])],
            'metadata INF' => [new KnowledgeChunk('tenant', 'docs', 'source', 'content', ['bad' => INF], [1])],
            'metadata resource' => [new KnowledgeChunk('tenant', 'docs', 'source', 'content', ['bad' => $resource], [1])],
            'invalid UTF-8 content' => [new KnowledgeChunk('tenant', 'docs', 'source', "\xB1", [], [1])],
        ];
    }

    public function test_batch_rejects_non_chunks_before_any_request(): void
    {
        [$client, $history] = $this->client([]);
        $this->expectException(KnowledgeStoreException::class);
        try {
            (new QdrantStore('http://qdrant.test', client: $client))->insertBatch([$this->chunk([1]), new \stdClass()]);
        } finally {
            self::assertCount(0, $history);
        }
    }

    /** @dataProvider invalidBatchEmbeddings */
    public function test_batch_rejects_invalid_or_mixed_embeddings_before_any_request(array $embeddings): void
    {
        [$client, $history] = $this->client([]);
        $this->expectException(KnowledgeStoreException::class);
        try {
            $chunks = array_map(fn (array $embedding) => $this->chunk($embedding), $embeddings);
            (new QdrantStore('http://qdrant.test', client: $client))->insertBatch($chunks);
        } finally {
            self::assertCount(0, $history);
        }
    }

    public static function invalidBatchEmbeddings(): array
    {
        return [
            'empty' => [[[], [1]]],
            'sparse' => [[[1], [0 => 1, 2 => 2]]],
            'non finite' => [[[1], [INF]]],
            'mixed dimensions' => [[[1, 2], [3]]],
        ];
    }

    public function test_deleteBySource_counts_matches_then_deletes_with_tenant_and_source_filters(): void
    {
        [$client, $history] = $this->client([
            new Response(200, [], json_encode(['result' => ['count' => 5]], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['result' => true], JSON_THROW_ON_ERROR)),
        ]);

        $store = new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null);
        $deleted = $store->deleteBySource('tenant-1', 'source.pdf');

        self::assertSame(5, $deleted);
        self::assertCount(2, (array)$history);

        // First request: count
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertStringContainsString('/collections/knowledge_chunks/points/count', (string) $history[0]['request']->getUri());
        $countBody = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame([
            ['key' => 'tenant_id', 'match' => ['value' => 'tenant-1']],
            ['key' => 'source', 'match' => ['value' => 'source.pdf']],
        ], $countBody['filter']['must']);
        self::assertTrue($countBody['exact']);

        // Second request: delete
        self::assertSame('POST', $history[1]['request']->getMethod());
        self::assertStringContainsString('/collections/knowledge_chunks/points/delete?wait=true', (string) $history[1]['request']->getUri());
        $deleteBody = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame([
            ['key' => 'tenant_id', 'match' => ['value' => 'tenant-1']],
            ['key' => 'source', 'match' => ['value' => 'source.pdf']],
        ], $deleteBody['filter']['must']);
    }

    public function test_deleteByTenant_counts_matches_then_deletes_with_tenant_filter_only(): void
    {
        [$client, $history] = $this->client([
            new Response(200, [], json_encode(['result' => ['count' => 3]], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['result' => true], JSON_THROW_ON_ERROR)),
        ]);

        $store = new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null);
        $deleted = $store->deleteByTenant('tenant-2');

        self::assertSame(3, $deleted);
        self::assertCount(2, $history);

        // First request: count
        self::assertSame('POST', $history[0]['request']->getMethod());
        $countBody = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame([
            ['key' => 'tenant_id', 'match' => ['value' => 'tenant-2']],
        ], $countBody['filter']['must']);

        // Second request: delete
        self::assertSame('POST', $history[1]['request']->getMethod());
        $deleteBody = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame([
            ['key' => 'tenant_id', 'match' => ['value' => 'tenant-2']],
        ], $deleteBody['filter']['must']);
    }

    public function test_deleteBySource_returns_zero_when_collection_not_found(): void
    {
        [$client, $history] = $this->client([
            new Response(404, [], json_encode(['status' => ['error' => 'not found']], JSON_THROW_ON_ERROR)),
        ]);

        $store = new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null);
        $deleted = $store->deleteBySource('tenant-1', 'source.pdf');

        self::assertSame(0, $deleted);
        self::assertCount(1, $history);
    }

    public function test_deleteByTenant_returns_zero_when_collection_not_found(): void
    {
        [$client, $history] = $this->client([
            new Response(404, [], json_encode(['status' => ['error' => 'not found']], JSON_THROW_ON_ERROR)),
        ]);

        $store = new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null);
        $deleted = $store->deleteByTenant('tenant-1');

        self::assertSame(0, $deleted);
        self::assertCount(1, $history);
    }

    public function test_deleteBySource_skips_delete_when_no_matches(): void
    {
        [$client, $history] = $this->client([
            new Response(200, [], json_encode(['result' => ['count' => 0]], JSON_THROW_ON_ERROR)),
        ]);

        $store = new QdrantStore('http://qdrant.test', client: $client, sleeper: static fn () => null);
        $deleted = $store->deleteBySource('tenant-1', 'nonexistent.pdf');

        self::assertSame(0, $deleted);
        self::assertCount(1, $history);
    }

    private function client(array $responses, array $headers = []): array
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return [new Client(['handler' => $stack, 'http_errors' => false, 'headers' => $headers]), $history];
    }

    private function chunk(array $embedding): KnowledgeChunk
    {
        return new KnowledgeChunk('tenant', 'docs', 'source', 'content', embedding: $embedding);
    }

    private function collection(int $size): string
    {
        return json_encode(['result' => ['config' => ['params' => ['vectors' => ['size' => $size]]]]], JSON_THROW_ON_ERROR);
    }

    private function errorBody(KnowledgeStoreException $exception): string
    {
        return explode(': ', $exception->getMessage(), 2)[1] ?? '';
    }
}
