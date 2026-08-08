<?php

namespace Peralta\AgentKit\Knowledge\Embedders;

use GuzzleHttp\Client;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Knowledge\Contracts\Embedder;

/**
 * Cohere Embeddings
 *
 * Usa a API Cohere para gerar embeddings.
 * Modelos: embed-english-v3.0 (1024 dimensões)
 *          embed-english-light-v3.0 (384 dimensões)
 */
class CohereEmbedder implements Embedder
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
        // Cohere aceita múltiplos textos por chamada (até 2048 por request)
        // Vamos processar em chunks de 100 por segurança
        $chunks = array_chunk($texts, 100);
        $all = [];

        try {
            foreach ($chunks as $batch) {
                $response = $this->http->post('v1/embed', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'texts' => $batch,
                        'model' => $this->config['model'] ?? 'embed-english-v3.0',
                        'input_type' => $this->config['input_type'] ?? 'search_document',
                        'embedding_types' => ['float'],
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);

                if (isset($data['embeddings'])) {
                    foreach ($data['embeddings'] as $embedding) {
                        $all[] = $embedding;
                    }
                } else {
                    throw new ProviderException('Resposta inesperada do Cohere: ' . json_encode($data));
                }
            }
        } catch (\Throwable $e) {
            throw new ProviderException("Erro gerando embeddings com Cohere: {$e->getMessage()}", 0, $e);
        }

        return $all;
    }

    public function dimensions(): int
    {
        $model = $this->config['model'] ?? 'embed-english-v3.0';

        return match ($model) {
            'embed-english-v3.0' => 1024,
            'embed-english-light-v3.0' => 384,
            'embed-multilingual-v3.0' => 1024,
            'embed-multilingual-light-v3.0' => 384,
            default => $this->config['dimensions'] ?? 1024,
        };
    }
}
