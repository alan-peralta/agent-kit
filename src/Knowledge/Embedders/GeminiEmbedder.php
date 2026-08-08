<?php

namespace Peralta\AgentKit\Knowledge\Embedders;

use GuzzleHttp\Client;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Knowledge\Contracts\Embedder;

/**
 * Google Gemini Embeddings
 *
 * Usa a API Google Generative AI para gerar embeddings.
 * Modelos: embedding-001 (768 dimensões)
 */
class GeminiEmbedder implements Embedder
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
        // Gemini processa um por vez por padrão
        // Mas pode processar múltiplos na mesma chamada
        $all = [];

        try {
            foreach ($texts as $text) {
                $response = $this->http->post('v1beta/models/embedding-001:embedContent', [
                    'query' => [
                        'key' => $this->config['api_key'],
                    ],
                    'json' => [
                        'model' => 'models/embedding-001',
                        'content' => [
                            'parts' => [
                                ['text' => $text],
                            ],
                        ],
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);

                if (isset($data['embedding']['values'])) {
                    $all[] = $data['embedding']['values'];
                } else {
                    throw new ProviderException('Resposta inesperada do Gemini: ' . json_encode($data));
                }
            }
        } catch (\Throwable $e) {
            throw new ProviderException("Erro gerando embeddings com Gemini: {$e->getMessage()}", 0, $e);
        }

        return $all;
    }

    public function dimensions(): int
    {
        // Gemini embedding-001 sempre retorna 768 dimensões
        return 768;
    }
}
