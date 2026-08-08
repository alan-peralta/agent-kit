<?php

namespace Peralta\AgentKit\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Peralta\AgentKit\Contracts\Provider;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\Exceptions\ProviderException;

abstract class AbstractProvider implements Provider
{
    protected Client $http;

    public function __construct(protected array $config)
    {
        $options = [
            'base_uri' => rtrim($config['base_url'], '/') . '/',
            'timeout' => $config['timeout'] ?? 60,
        ];

        if (isset($config['handler'])) {
            $options['handler'] = $config['handler'];
        }

        $this->http = new Client($options);
    }

    protected function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ProviderException("Resposta JSON inválida do provider {$this->name()}: " . substr($body, 0, 200));
            }

            return $decoded;
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $message .= ' | Body: ' . substr((string) $e->getResponse()->getBody(), 0, 500);
            }
            throw new ProviderException("Erro chamando {$this->name()}: {$message}", $e->getCode(), $e);
        }
    }

    /**
     * Converte tools (instâncias) pro formato do provider específico.
     * Cada provider implementa do seu jeito.
     *
     * @param  Tool[]  $tools
     */
    abstract protected function formatTools(array $tools): array;
}
