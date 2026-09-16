<?php

namespace Peralta\AgentKit\Tests\Unit\Knowledge;

use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Knowledge\Embedders\GeminiEmbedder;
use Peralta\AgentKit\Tests\Unit\Providers\Concerns\MocksGuzzleHttp;
use PHPUnit\Framework\TestCase;

class GeminiEmbedderTest extends TestCase
{
    use MocksGuzzleHttp;

    public function test_embed_sends_api_key_in_header_not_in_query_string()
    {
        [$embedder, $history] = $this->embedder([
            new Response(200, [], json_encode(['embedding' => ['values' => [0.1, 0.2, 0.3]]])),
        ]);

        $vector = $embedder->embed('olá');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://api.test/v1beta/models/embedding-001:embedContent', (string) $request->getUri());
        $this->assertSame('secret-key', $request->getHeaderLine('x-goog-api-key'));
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertStringNotContainsString('secret-key', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('models/embedding-001', $body['model']);
        $this->assertSame('olá', $body['content']['parts'][0]['text']);
        $this->assertSame([0.1, 0.2, 0.3], $vector);
    }

    public function test_embed_batch_issues_one_request_per_text()
    {
        [$embedder, $history] = $this->embedder([
            new Response(200, [], json_encode(['embedding' => ['values' => [0.5]]])),
            new Response(200, [], json_encode(['embedding' => ['values' => [0.25]]])),
        ]);

        $vectors = $embedder->embedBatch(['a', 'b']);

        $this->assertCount(2, $history);
        $this->assertSame([[0.5], [0.25]], $vectors);
        foreach ($history as $entry) {
            $this->assertSame('secret-key', $entry['request']->getHeaderLine('x-goog-api-key'));
        }
    }

    public function test_throws_provider_exception_on_unexpected_response()
    {
        [$embedder] = $this->embedder([
            new Response(200, [], json_encode(['error' => 'boom'])),
        ]);

        $this->expectException(ProviderException::class);
        $embedder->embed('oi');
    }

    public function test_dimensions_is_768()
    {
        [$embedder] = $this->embedder([]);

        $this->assertSame(768, $embedder->dimensions());
    }

    private function embedder(array $responses): array
    {
        [$stack, $history] = $this->mockHandlerStack($responses);

        $embedder = new GeminiEmbedder([
            'base_url' => 'http://api.test',
            'api_key' => 'secret-key',
            'handler' => $stack,
        ]);

        return [$embedder, $history];
    }
}
