<?php

namespace Peralta\AgentKit\Knowledge\Tools;

use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\Knowledge\Contracts\Embedder;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Tools\AbstractTool;

/**
 * Tool de busca em base de conhecimento, registrada automaticamente
 * quando o consumidor chama ->knowledgeBase('faq') no Agent.
 */
class KnowledgeSearchTool extends AbstractTool
{
    public function __construct(
        protected string $collection,
        protected string $description,
        protected Embedder $embedder,
        protected KnowledgeStore $store,
        protected int $limit = 5,
        protected float $minRelevance = 0.65,
    ) {}

    public function name(): string
    {
        // Sanitiza pra nome válido de tool
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $this->collection);
        return "consultar_{$clean}";
    }

    public function description(): string
    {
        return $this->description;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pergunta' => [
                    'type' => 'string',
                    'description' => 'Pergunta ou termo de busca em linguagem natural.',
                ],
            ],
            'required' => ['pergunta'],
        ];
    }

    public function handle(array $input, Context $context): mixed
    {
        if (empty($context->tenantId)) {
            return [
                'encontrado' => false,
                'erro' => 'tenant_id ausente no contexto da consulta.',
            ];
        }

        $embedding = $this->embedder->embed($input['pergunta']);

        $resultados = $this->store->search(
            embedding: $embedding,
            tenantId: (string) $context->tenantId,
            collection: $this->collection,
            limit: $this->limit,
            minRelevance: $this->minRelevance,
        );

        if (empty($resultados)) {
            return [
                'encontrado' => false,
                'mensagem' => 'Nenhuma informação relevante encontrada na base de conhecimento.',
            ];
        }

        return [
            'encontrado' => true,
            'resultados' => array_map(fn($chunk) => [
                'conteudo' => $chunk->content,
                'fonte' => $chunk->source,
                'relevancia' => round($chunk->relevance, 3),
            ], $resultados),
        ];
    }
}
