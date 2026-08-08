<?php

namespace Peralta\AgentKit\Knowledge\Contracts;

interface Embedder
{
    /**
     * Gera embedding de um único texto.
     *
     * @return float[]
     */
    public function embed(string $text): array;

    /**
     * Gera embeddings em batch (mais eficiente).
     *
     * @param  string[]  $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array;

    public function dimensions(): int;
}
