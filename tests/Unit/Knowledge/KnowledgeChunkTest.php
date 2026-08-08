<?php

namespace Peralta\AgentKit\Tests\Unit\Knowledge;

use Peralta\AgentKit\Knowledge\KnowledgeChunk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KnowledgeChunkTest extends TestCase
{
    #[DataProvider('ids')]
    public function test_it_preserves_the_id(int|string|null $id): void
    {
        $chunk = new KnowledgeChunk('tenant', 'faq', 'source', 'content', id: $id);

        self::assertSame($id, $chunk->id);
    }

    public static function ids(): array
    {
        return [
            'integer' => [42],
            'UUID string' => ['9d6c1a24-bad0-4f26-96c2-2fca7dbeb77e'],
            'null' => [null],
        ];
    }
}
