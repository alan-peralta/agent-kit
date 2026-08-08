<?php

namespace Peralta\AgentKit\Knowledge\Embedders;

use GuzzleHttp\Client;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Knowledge\Contracts\Embedder;

class OpenAIEmbedder implements Embedder
{
    protected Client $http;

    public function __construct(protected array $config)
    {
        $this->http = new Client([
            'base_uri' => rtrim($config['base_url'], '/') . '/',
            'timeout' => 60,
        ]);
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        // OpenAI aceita até 2048 inputs por chamada, mas por segurança 100 por vez
        $chunks = array_chunk($texts, 100);
        $all = [];

        foreach ($chunks as $batch) {
            try {
                $response = $this->http->post('embeddings', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->config['model'],
                        'input' => $batch,
                        'dimensions' => $this->config['dimensions'] ?? null,
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);
                foreach ($data['data'] as $item) {
                    $all[] = $item['embedding'];
                }
            } catch (\Throwable $e) {
                throw new ProviderException("Erro gerando embeddings: {$e->getMessage()}", 0, $e);
            }
        }

        return $all;
    }

    public function dimensions(): int
    {
        return $this->config['dimensions'] ?? 1536;
    }
}
