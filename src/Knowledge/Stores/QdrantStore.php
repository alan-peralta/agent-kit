<?php

namespace Peralta\AgentKit\Knowledge\Stores;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Peralta\AgentKit\Exceptions\KnowledgeStoreException;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use stdClass;

class QdrantStore implements KnowledgeStore
{
    protected ClientInterface $client;

    protected bool $collectionReady = false;

    protected ?int $collectionDimension = null;

    public function __construct(
        protected string $url,
        protected ?string $apiKey = null,
        protected string $collection = 'knowledge_chunks',
        protected float $timeout = 30.0,
        protected int $batchSize = 100,
        ?ClientInterface $client = null,
        protected ?Closure $sleeper = null,
    ) {
        $this->url = rtrim($this->url, '/');

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['api-key'] = $this->apiKey;
        }

        $this->client = $client ?? new Client([
            'base_uri' => $this->url . '/',
            'timeout' => $this->timeout,
            'headers' => $headers,
            'http_errors' => false,
        ]);
        $this->sleeper ??= static function (int $microseconds): void {
            usleep($microseconds);
        };
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

        $dimension = null;
        foreach ($chunks as $chunk) {
            if (! $chunk instanceof KnowledgeChunk) {
                throw new KnowledgeStoreException('Qdrant batch elements must be KnowledgeChunk instances.');
            }

            $this->validateConfiguration($chunk->embedding);
            $dimension ??= count($chunk->embedding);

            if (count($chunk->embedding) !== $dimension) {
                throw new KnowledgeStoreException(sprintf(
                    'Qdrant batch embedding dimension %d does not match first embedding dimension %d.',
                    count($chunk->embedding),
                    $dimension,
                ));
            }
        }

        $points = array_map(fn (KnowledgeChunk $chunk): array => $this->point($chunk), $chunks);
        try {
            json_encode(['points' => $points], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new KnowledgeStoreException('Qdrant points must be JSON serializable.');
        }

        /** @var KnowledgeChunk $first */
        $first = $chunks[array_key_first($chunks)];
        $this->ensureCollection($first->embedding);

        $path = '/collections/'.rawurlencode($this->collection).'/points?wait=true';
        foreach (array_chunk($points, $this->batchSize) as $batch) {
            $this->request('PUT', $path, ['points' => $batch]);
        }
    }

    public function search(
        array $embedding,
        string $tenantId,
        ?string $collection = null,
        int $limit = 5,
        float $minRelevance = 0.0,
    ): array {
        $this->validateConfiguration($embedding);

        if (trim($tenantId) === '') {
            throw new KnowledgeStoreException('Qdrant search tenant ID must not be empty.');
        }

        if ($collection !== null && trim($collection) === '') {
            throw new KnowledgeStoreException('Qdrant search collection must not be empty when provided.');
        }

        if ($limit < 1) {
            throw new KnowledgeStoreException('Qdrant search limit must be at least 1.');
        }

        if (! is_finite($minRelevance)) {
            throw new KnowledgeStoreException('Qdrant search minimum relevance must be finite.');
        }

        $must = [
            ['key' => 'tenant_id', 'match' => ['value' => $tenantId]],
        ];
        if ($collection !== null) {
            $must[] = ['key' => 'collection', 'match' => ['value' => $collection]];
        }

        try {
            $response = $this->request(
                'POST',
                '/collections/'.rawurlencode($this->collection).'/points/query',
                [
                    'query' => $embedding,
                    'filter' => ['must' => $must],
                    'limit' => $limit,
                    'score_threshold' => $minRelevance,
                    'with_payload' => true,
                    'with_vector' => false,
                ],
            );
        } catch (KnowledgeStoreException $exception) {
            if ($exception->getCode() === 404) {
                return [];
            }

            throw $exception;
        }

        $points = $this->searchPoints($response);

        return array_map(
            fn (mixed $point): KnowledgeChunk => $this->searchChunk($point, $tenantId, $collection),
            $points,
        );
    }

    /** @param array<string, mixed> $response */
    protected function searchPoints(array $response): array
    {
        if (! array_key_exists('result', $response) || ! is_array($response['result'])) {
            throw new KnowledgeStoreException('Qdrant search response has no valid result.');
        }

        $result = $response['result'];
        if (array_is_list($result)) {
            return $result;
        }

        if (! array_key_exists('points', $result) || ! is_array($result['points']) || ! array_is_list($result['points'])) {
            throw new KnowledgeStoreException('Qdrant search response result has no valid points list.');
        }

        return $result['points'];
    }

    protected function searchChunk(mixed $point, string $tenantId, ?string $collection): KnowledgeChunk
    {
        if (! is_array($point)) {
            throw new KnowledgeStoreException('Qdrant search response contains an invalid point.');
        }

        $id = $point['id'] ?? null;
        if (! $this->isValidPointId($id)) {
            throw new KnowledgeStoreException('Qdrant search response point has an invalid ID.');
        }

        $score = $point['score'] ?? null;
        if ((! is_int($score) && ! is_float($score)) || ! is_finite((float) $score)) {
            throw new KnowledgeStoreException('Qdrant search response point has an invalid score.');
        }

        $payload = $point['payload'] ?? null;
        if (! is_array($payload)) {
            throw new KnowledgeStoreException('Qdrant search response point has an invalid payload.');
        }

        foreach (['tenant_id', 'collection', 'source', 'content'] as $field) {
            if (! array_key_exists($field, $payload) || ! is_string($payload[$field])) {
                throw new KnowledgeStoreException(sprintf(
                    'Qdrant search response point payload has an invalid %s field.',
                    $field,
                ));
            }
        }

        if ($payload['tenant_id'] !== $tenantId) {
            throw new KnowledgeStoreException('Qdrant search response violated tenant isolation.');
        }

        if ($collection !== null && $payload['collection'] !== $collection) {
            throw new KnowledgeStoreException('Qdrant search response violated collection isolation.');
        }

        $metadata = $payload['metadata'] ?? null;

        return new KnowledgeChunk(
            tenantId: $payload['tenant_id'],
            collection: $payload['collection'],
            source: $payload['source'],
            content: $payload['content'],
            metadata: is_array($metadata) || $metadata instanceof stdClass
                ? $this->normalizeMetadata($metadata)
                : [],
            embedding: [],
            relevance: (float) $score,
            id: $id,
        );
    }

    protected function normalizeMetadata(array|stdClass $metadata): array
    {
        $items = $metadata instanceof stdClass ? get_object_vars($metadata) : $metadata;

        return array_map(function (mixed $value): mixed {
            if ($value instanceof stdClass || is_array($value)) {
                return $this->normalizeMetadata($value);
            }

            return $value;
        }, $items);
    }

    public function deleteBySource(string $tenantId, string $source): int
    {
        return $this->deleteByFilter([
            $this->match('tenant_id', $tenantId),
            $this->match('source', $source),
        ]);
    }

    public function deleteByTenant(string $tenantId): int
    {
        return $this->deleteByFilter([
            $this->match('tenant_id', $tenantId),
        ]);
    }

    protected function deleteByFilter(array $must): int
    {
        $base = '/collections/'.rawurlencode($this->collection).'/points';

        try {
            $count = $this->request('POST', $base.'/count', [
                'filter' => ['must' => $must],
                'exact' => true,
            ]);
        } catch (KnowledgeStoreException $exception) {
            if ($exception->getCode() === 404) {
                return 0;
            }

            throw $exception;
        }

        $matched = (int) ($count['result']['count'] ?? 0);
        if ($matched === 0) {
            return 0;
        }

        $this->request('POST', $base.'/delete?wait=true', [
            'filter' => ['must' => $must],
        ]);

        return $matched;
    }

    protected function match(string $key, string $value): array
    {
        return ['key' => $key, 'match' => ['value' => $value]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $json = []): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => null,
            'api-key' => null,
        ];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['api-key'] = $this->apiKey;
        }

        $options = [
            'headers' => $headers,
            'http_errors' => false,
            'timeout' => $this->timeout,
        ];

        if ($json !== []) {
            $options['json'] = $json;
        }

        $uri = $this->url.'/'.ltrim($path, '/');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->client->request($method, $uri, $options);
            } catch (GuzzleException $exception) {
                throw new KnowledgeStoreException(
                    'Qdrant transport request failed: '.$this->sanitize($exception->getMessage()),
                    (int) $exception->getCode(),
                );
            }

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            if (($status === 429 || $status >= 500) && $attempt < 2) {
                ($this->sleeper)($this->retryDelay($response->getHeaderLine('Retry-After'), $attempt));
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new KnowledgeStoreException(
                    sprintf('Qdrant request failed with HTTP %d: %s', $status, $this->sanitize($body)),
                    $status,
                );
            }

            if ($body === '') {
                return [];
            }

            try {
                $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new KnowledgeStoreException(
                    'Qdrant returned invalid JSON: '.$this->sanitize($body),
                    $status,
                    $exception,
                );
            }

            if (! $decoded instanceof stdClass) {
                throw new KnowledgeStoreException('Qdrant returned invalid JSON: expected a top-level object.', $status);
            }

            $normalized = $this->normalizeJsonValue($decoded);
            if ($normalized instanceof stdClass) {
                return [];
            }

            if (! is_array($normalized)) {
                throw new KnowledgeStoreException('Qdrant returned invalid JSON: expected a top-level object.', $status);
            }

            return $normalized;
        }

        throw new KnowledgeStoreException('Qdrant request failed after retries.');
    }

    protected function normalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }

            return array_map(fn (mixed $item): mixed => $this->normalizeJsonValue($item), $properties);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeJsonValue($item), $value);
        }

        return $value;
    }

    protected function ensureCollection(array $embedding): void
    {
        $this->validateConfiguration($embedding);
        $dimension = count($embedding);

        if ($this->collectionReady) {
            if ($this->collectionDimension !== $dimension) {
                throw new KnowledgeStoreException(sprintf(
                    'Qdrant collection dimension %d does not match embedding dimension %d.',
                    $this->collectionDimension,
                    $dimension,
                ));
            }

            return;
        }

        $path = '/collections/'.rawurlencode($this->collection);

        try {
            $response = $this->request('GET', $path);
            $this->validateCollectionDimension($response, $dimension);
        } catch (KnowledgeStoreException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }

            try {
                $this->request('PUT', $path, [
                    'vectors' => [
                        'size' => $dimension,
                        'distance' => 'Cosine',
                    ],
                ]);
            } catch (KnowledgeStoreException $creationException) {
                if (! in_array($creationException->getCode(), [400, 409], true)) {
                    throw $creationException;
                }

                $this->confirmRacedCollection($path, $dimension, $creationException);
            }
        }

        $this->collectionDimension = $dimension;
        $this->collectionReady = true;
    }

    protected function validateConfiguration(array $embedding): void
    {
        if (trim($this->url) === '') {
            throw new KnowledgeStoreException('Qdrant URL must not be empty.');
        }

        if (trim($this->collection) === '') {
            throw new KnowledgeStoreException('Qdrant collection must not be empty.');
        }

        if ($this->batchSize < 1) {
            throw new KnowledgeStoreException('Qdrant batch size must be at least 1.');
        }

        if ($embedding === []) {
            throw new KnowledgeStoreException('Qdrant embedding must not be empty.');
        }

        if (! array_is_list($embedding)) {
            throw new KnowledgeStoreException('Qdrant embedding must be a dense list.');
        }

        foreach ($embedding as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new KnowledgeStoreException('Qdrant embedding values must be finite integers or floats.');
            }
        }
    }

    /** @return array{id: int|string, vector: array, payload: array<string, mixed>} */
    protected function point(KnowledgeChunk $chunk): array
    {
        if ($chunk->id !== null && ! $this->isValidPointId($chunk->id)) {
            throw new KnowledgeStoreException(is_int($chunk->id)
                ? 'Qdrant point ID integer must be unsigned.'
                : 'Qdrant point ID string must be a canonical UUID.');
        }

        return [
            'id' => $chunk->id ?? $this->uuidV4(),
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

    protected function isValidPointId(mixed $id): bool
    {
        if (is_int($id)) {
            return $id >= 0;
        }

        return is_string($id) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id,
        ) === 1;
    }

    protected function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }

    /** @param array<string, mixed> $response */
    protected function validateCollectionDimension(array $response, int $expectedDimension): void
    {
        $dimension = $response['result']['config']['params']['vectors']['size'] ?? null;

        if (! is_int($dimension)) {
            throw new KnowledgeStoreException('Qdrant collection response has no valid integer vector dimension.');
        }

        if ($dimension !== $expectedDimension) {
            throw new KnowledgeStoreException(sprintf(
                'Qdrant collection dimension %d does not match embedding dimension %d.',
                $dimension,
                $expectedDimension,
            ));
        }
    }

    protected function sanitize(string $body): string
    {
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $body = str_replace($this->apiKey, '[redacted]', $body);
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($body, 0, 500, 'UTF-8');
        }

        $body = substr($body, 0, 500);
        while ($body !== '' && preg_match('//u', $body) !== 1) {
            $body = substr($body, 0, -1);
        }

        return $body;
    }

    protected function retryDelay(string $retryAfter, int $attempt): int
    {
        $microseconds = null;

        if (preg_match('/^\s*\d+\s*$/', $retryAfter) === 1) {
            $microseconds = min(1, (int) trim($retryAfter)) * 1_000_000;
        } elseif ($retryAfter !== '') {
            $timestamp = strtotime($retryAfter);
            if ($timestamp !== false) {
                $microseconds = min(1, max(0, $timestamp - time())) * 1_000_000;
            }
        }

        return min(1_000_000, $microseconds ?? (($attempt + 1) * 50_000));
    }

    protected function confirmRacedCollection(
        string $path,
        int $dimension,
        KnowledgeStoreException $creationException,
    ): void {
        $lastStatus = 404;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->request('GET', $path);
                $this->validateCollectionDimension($response, $dimension);

                return;
            } catch (KnowledgeStoreException $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }

                $lastStatus = $exception->getCode();
                if ($attempt < 2) {
                    ($this->sleeper)(($attempt + 1) * 50_000);
                }
            }
        }

        throw new KnowledgeStoreException(
            sprintf(
                'Qdrant collection creation raced (HTTP %d) but could not confirm collection after 3 attempts; last confirmation returned HTTP %d.',
                $creationException->getCode(),
                $lastStatus,
            ),
            $creationException->getCode(),
        );
    }
}
