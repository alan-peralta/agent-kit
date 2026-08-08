<?php

namespace Peralta\AgentKit\Knowledge;

use Peralta\AgentKit\Knowledge\Contracts\Embedder;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;

class KnowledgeIndexer
{
    public function __construct(
        protected Embedder $embedder,
        protected KnowledgeStore $store,
    ) {}

    /**
     * Indexa um texto longo: quebra em chunks, gera embeddings, persiste.
     */
    public function indexText(
        string $tenantId,
        string $collection,
        string $source,
        string $text,
        array $metadata = [],
        int $chunkSize = 500,
        int $chunkOverlap = 50,
        bool $replaceExisting = true,
    ): int {
        if ($replaceExisting) {
            $this->store->deleteBySource($tenantId, $source);
        }

        $chunks = $this->chunk($text, $chunkSize, $chunkOverlap);
        if (empty($chunks)) return 0;

        $embeddings = $this->embedder->embedBatch($chunks);

        $knowledgeChunks = [];
        foreach ($chunks as $i => $chunkText) {
            $knowledgeChunks[] = new KnowledgeChunk(
                tenantId: $tenantId,
                collection: $collection,
                source: $source,
                content: $chunkText,
                metadata: array_merge($metadata, ['chunk_index' => $i]),
                embedding: $embeddings[$i],
            );
        }

        $this->store->insertBatch($knowledgeChunks);

        return count($knowledgeChunks);
    }

    public function deleteBySource(string $tenantId, string $source): int
    {
        return $this->store->deleteBySource($tenantId, $source);
    }

    /**
     * Chunking que tenta respeitar parágrafos antes de cortar por palavras.
     */
    protected function chunk(string $text, int $size, int $overlap): array
    {
        $text = trim($text);
        if ($text === '') return [];

        $paragrafos = preg_split('/\n\s*\n/', $text);
        $chunks = [];
        $atual = '';

        foreach ($paragrafos as $p) {
            $p = trim($p);
            if ($p === '') continue;

            $palavrasAtual = str_word_count($atual);
            $palavrasP = str_word_count($p);

            if ($palavrasAtual + $palavrasP <= $size) {
                $atual .= ($atual ? "\n\n" : '') . $p;
            } else {
                if ($atual !== '') $chunks[] = $atual;

                if ($palavrasP > $size) {
                    // parágrafo gigante: subdivide por palavras
                    $palavras = preg_split('/\s+/', $p);
                    $passo = max(1, $size - $overlap);
                    for ($i = 0; $i < count($palavras); $i += $passo) {
                        $chunks[] = implode(' ', array_slice($palavras, $i, $size));
                        if ($i + $size >= count($palavras)) break;
                    }
                    $atual = '';
                } else {
                    $atual = $p;
                }
            }
        }

        if ($atual !== '') $chunks[] = $atual;

        return $chunks;
    }
}
