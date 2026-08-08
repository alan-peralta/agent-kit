<?php

namespace Peralta\AgentKit\Tests\Feature;

use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\Stores\QdrantStore;
use Peralta\AgentKit\Tests\TestCase;

class QdrantStoreBindingTest extends TestCase
{
    public function test_it_resolves_qdrant_store_from_configuration(): void
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

        $this->assertInstanceOf(
            QdrantStore::class,
            $this->app->make(KnowledgeStore::class),
        );
    }
}
