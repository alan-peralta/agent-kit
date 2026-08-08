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
        if (!$url) {
            self::markTestSkipped('Set QDRANT_TEST_URL to run live Qdrant tests.');
        }

        $tenant = 'agent-kit-test-'.bin2hex(random_bytes(6));
        $store = new QdrantStore(
            url: $url,
            apiKey: getenv('QDRANT_TEST_API_KEY') ?: null,
            collection: getenv('QDRANT_TEST_COLLECTION') ?: 'agent_kit_tests',
        );

        try {
            $store->insert(new KnowledgeChunk(
                $tenant,
                'faq',
                'smoke',
                'Qdrant works',
                [],
                [1.0, 0.0, 0.0],
            ));

            $results = $store->search([1.0, 0.0, 0.0], $tenant, 'faq', 1, 0.9);

            self::assertCount(1, $results);
            self::assertSame('Qdrant works', $results[0]->content);
        } finally {
            $store->deleteByTenant($tenant);
        }
    }
}
