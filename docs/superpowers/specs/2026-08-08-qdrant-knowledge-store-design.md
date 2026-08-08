# Qdrant Knowledge Store Design

## Objective

Add Qdrant as a Knowledge Store for Agent Kit while preserving the existing
`KnowledgeStore` contract and keeping pgvector as the default. The integration
must work with both Qdrant Cloud and self-hosted installations.

## Scope

This change adds one store only: Qdrant. It does not introduce a generic HTTP
abstraction for future vector stores and does not add Pinecone, Weaviate, or
other providers.

The implementation will use Qdrant's REST API through the Guzzle dependency
already required by the package. No third-party Qdrant PHP SDK will be added.

## Architecture

`QdrantStore` will implement the existing `KnowledgeStore` interface. The
`KnowledgeIndexer` and `KnowledgeSearchTool` flows remain unchanged. Consumers
select the new store with `AGENT_KNOWLEDGE_STORE=qdrant`.

The following package areas will change:

- Add `QdrantStore` for HTTP integration, collection provisioning, point
  persistence, vector search, and filtered deletion.
- Add a `qdrant` entry to `knowledge.stores` configuration.
- Extend `AgentKitServiceProvider` to resolve the `qdrant` driver.
- Widen `KnowledgeChunk::$id` from `?int` to `int|string|null`, allowing Qdrant
  UUIDs while retaining pgvector integer IDs.
- Add `KnowledgeStoreException` for store configuration and runtime failures.
- Update `.env.example` and package documentation. Composer dependencies remain
  unchanged because the existing Guzzle dependency is sufficient.
- Add automated tests that mock HTTP and do not require a running Qdrant.

## Physical and Logical Collection Model

Qdrant uses one shared physical collection, configured by
`QDRANT_COLLECTION`. Agent Kit's tenant and collection boundaries remain
logical payload fields rather than becoming physical Qdrant collection names.

Each Qdrant point has a generated UUID and this payload:

```text
tenant_id
collection
source
content
metadata
```

All searches and deletions are constrained by tenant. A search additionally
filters by the logical collection when the caller provides one.

## Configuration

The new driver supports these environment variables:

```env
AGENT_KNOWLEDGE_STORE=qdrant
QDRANT_URL=http://localhost:6333
QDRANT_API_KEY=
QDRANT_COLLECTION=knowledge_chunks
QDRANT_TIMEOUT=30
QDRANT_BATCH_SIZE=100
```

`QDRANT_URL` is required when Qdrant is selected. `QDRANT_API_KEY` is optional
to support unsecured local and private self-hosted deployments. The API key is
sent using Qdrant's expected request header when configured. The store
normalizes the base URL before building request paths.

Timeout and batch size have the defaults shown above and may be overridden in
published configuration.

## Automatic Collection Provisioning

Before the first write in a store instance, `QdrantStore` checks whether the
configured physical collection exists. If it does not, the store creates it
using:

- the first embedding's dimension as the vector size;
- cosine distance;
- Qdrant defaults for other collection parameters.

Provisioning is idempotent and treats a concurrent successful creation as a
valid outcome. After validation succeeds, the result is cached for the life of
that store instance only. Each new process validates the remote collection
again.

For an existing collection, the store verifies that its vector size matches the
embedding being written. A mismatch raises a clear `KnowledgeStoreException`
before attempting the point upsert.

Search and deletion cannot provision an absent collection because they do not
have a source embedding from which to determine vector dimensions. In that
case, search returns an empty array and deletion returns `0`; the first write
will provision the collection.

## Write Flow

`insert()` converts one `KnowledgeChunk` into a Qdrant point and performs an
upsert. `insertBatch()` returns immediately for an empty input and otherwise:

1. validates or provisions the collection using the first embedding;
2. rejects missing, empty, or differently sized embeddings and verifies that
   all embeddings in the batch are compatible;
3. assigns a UUID to every point;
4. maps chunk fields to the shared payload schema;
5. partitions points according to `QDRANT_BATCH_SIZE`;
6. upserts each batch.

Generated UUIDs are returned only when points are later retrieved; the current
write contract remains `void`.

## Search Flow

`search()` sends the query embedding to Qdrant with:

- a mandatory exact-match filter on `tenant_id`;
- an optional exact-match filter on logical `collection`;
- the requested result limit;
- the requested minimum relevance as Qdrant's score threshold.

Because the physical collection uses cosine distance, Qdrant's returned score
maps directly to `KnowledgeChunk::$relevance`. Each result maps its point UUID,
payload, and score to `KnowledgeChunk`. Returned chunks omit their stored vector,
matching the existing `PgvectorStore` behavior.

## Delete Flow

`deleteBySource()` deletes points matching `tenant_id + source`. This
intentionally preserves the existing pgvector semantics, where a source is
tenant-wide rather than scoped to one logical collection.

`deleteByTenant()` deletes every point matching `tenant_id`.

Qdrant deletion responses do not expose an affected-row count compatible with
the existing contract. The store counts matching points immediately before
deletion and returns that value. The value therefore represents the matched
count at deletion time; as with any non-transactional remote count followed by
delete, concurrent writes may affect the final physical number removed.

## HTTP Reliability and Error Handling

The store performs at most two retries after the initial request for idempotent
operations that receive HTTP `429` or `5xx`, using a short bounded backoff.
Validation and other non-retriable `4xx` responses fail immediately.

`KnowledgeStoreException` covers:

- missing or invalid configuration;
- transport and timeout failures;
- unexpected HTTP responses;
- collection dimension mismatches;
- malformed response payloads.

Exception messages include the operation, HTTP status when available, and a
bounded sanitized response excerpt. They never include the Qdrant API key or
authorization headers.

## Backward Compatibility

Pgvector remains the default driver and requires no new configuration. Its
storage, search, and deletion behavior does not change. Widening the public ID
property permits all previous integer values and adds string UUID support.

Existing `KnowledgeIndexer` and agent-facing APIs are unchanged. Applications
switch stores through configuration only.

## Testing Strategy

The default test suite uses mocked HTTP responses and covers:

- service-provider resolution of the Qdrant driver;
- requests with and without an API key;
- automatic collection creation with the correct dimension and cosine metric;
- concurrent collection-creation behavior;
- single and batch upserts;
- partitioning according to configured batch size;
- mandatory tenant isolation during search;
- optional logical-collection filtering;
- limit and minimum relevance forwarding;
- UUID, payload, and score mapping to `KnowledgeChunk`;
- deletion by tenant and by tenant plus source;
- accurate deletion counts;
- existing-collection dimension mismatch;
- retry behavior for `429` and `5xx` responses;
- immediate failure for non-retriable `4xx` responses;
- sanitization of errors;
- continued pgvector compatibility with the widened ID type.

An opt-in integration test may run against a real Qdrant endpoint when explicit
test environment variables are present. It remains disabled by default and is
not required for the normal package test suite.

## Acceptance Criteria

The design is complete when:

1. pgvector continues to work without configuration changes;
2. setting the Qdrant driver and endpoint enables the same indexing, searching,
   and deletion workflows through `KnowledgeStore`;
3. Qdrant Cloud works with an API key and self-hosted Qdrant works without one;
4. the shared collection enforces tenant isolation on every read and delete;
5. the physical collection is provisioned automatically and rejects incompatible
   vector dimensions with a clear error;
6. all default tests pass without external network access.
