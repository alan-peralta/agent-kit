<?php

namespace Peralta\AgentKit\Knowledge\Embedders;

use GuzzleHttp\Client;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Knowledge\Contracts\Embedder;

/**
 * Mistral AI Embeddings
 *
 * Usa a API Mistral para gerar embeddings.
 * Modelo: mistral-embed (1024 dimensões)
 *
 * Qualidade: Excelente (MTEB #3)
 * Custo: $0.10 por 1M tokens
 * Multilíngue: Sim
 */
class MistralEmbedder implements Embedder
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
        // Mistral aceita múltiplos textos por chamada
        // Vamos processar em chunks de 100 por segurança
        $chunks = array_chunk($texts, 100);
        $all = [];

        try {
            foreach ($chunks as $batch) {
                $response = $this->http->post('embeddings', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->config['model'] ?? 'mistral-embed',
                        'input' => $batch,
                        'encoding_format' => 'float',
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);

                if (isset($data['data'])) {
                    foreach ($data['data'] as $item) {
                        $all[] = $item['embedding'];
                    }
                } else {
                    throw new ProviderException('Resposta inesperada do Mistral: ' . json_encode($data));
                }
            }
        } catch (\Throwable $e) {
            throw new ProviderException("Erro gerando embeddings com Mistral: {$e->getMessage()}", 0, $e);
        }

        return $all;
    }

    public function dimensions(): int
    {
        // Mistral embed sempre retorna 1024 dimensões
        return 1024;
    }
}
