<?php

namespace Peralta\AgentKit\Knowledge\Embedders;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Knowledge\Contracts\Embedder;

/**
 * AWS Bedrock Titan Embeddings
 *
 * Usa AWS Bedrock para gerar embeddings via Titan.
 * Modelo: amazon.titan-embed-text-v2:0 (1536 dimensões)
 *
 * Qualidade: Excelente (MTEB top 10)
 * Custo: $0.13 por 1M input tokens, $0.26 por 1M output tokens
 * Multilíngue: Sim (~100 idiomas)
 *
 * Requer: AWS SDK (composer require aws/aws-sdk-php)
 */
class AwsTitanEmbedder implements Embedder
{
    protected BedrockRuntimeClient $client;
    protected string $modelId;

    public function __construct(protected array $config)
    {
        if (!class_exists(BedrockRuntimeClient::class)) {
            throw new ProviderException(
                'AWS SDK não instalado. Execute: composer require aws/aws-sdk-php'
            );
        }

        $this->modelId = $config['model'] ?? 'amazon.titan-embed-text-v2:0';

        $clientConfig = [
            'version' => 'latest',
            'region' => $config['region'] ?? env('AWS_REGION', 'us-east-1'),
        ];

        // Se tiver credentials no config, usa
        if (isset($config['key']) && isset($config['secret'])) {
            $clientConfig['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }
        // Senão, usa credenciais do ambiente/IAM

        $this->client = new BedrockRuntimeClient($clientConfig);
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        // AWS Titan processa um por vez
        // Mas podemos paralelizar se necessário
        $all = [];

        try {
            foreach ($texts as $text) {
                $response = $this->client->invokeModel([
                    'modelId' => $this->modelId,
                    'contentType' => 'application/json',
                    'body' => json_encode([
                        'inputText' => $text,
                    ]),
                ]);

                $body = json_decode((string) $response['body'], true);

                if (isset($body['embedding'])) {
                    $all[] = $body['embedding'];
                } else {
                    throw new ProviderException(
                        'Resposta inesperada do AWS Titan: ' . json_encode($body)
                    );
                }
            }
        } catch (\Throwable $e) {
            throw new ProviderException(
                "Erro gerando embeddings com AWS Titan: {$e->getMessage()}",
                0,
                $e
            );
        }

        return $all;
    }

    public function dimensions(): int
    {
        // Titan embed text v2 sempre retorna 1536 dimensões
        // Titan embed text v1 retorna 1024
        return str_contains($this->modelId, 'v2') ? 1536 : 1024;
    }
}
