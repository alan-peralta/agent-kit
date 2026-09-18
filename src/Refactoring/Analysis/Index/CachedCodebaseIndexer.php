<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Index;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Support\ProjectRoot;

final class CachedCodebaseIndexer implements CodebaseIndexBuilder
{
    /** @var array<string, array{fingerprint: string, index: CodebaseIndex}> insertion order doubles as LRU order */
    private array $entries = [];

    public function __construct(
        private readonly CodebaseIndexBuilder $inner,
        private readonly ProjectFingerprint $fingerprint,
        private readonly int $maxEntries = 1,
    ) {
        if ($maxEntries < 1) {
            throw new InvalidArgumentException('The index cache must keep at least one entry.');
        }
    }

    public function build(string $root): CodebaseIndex
    {
        $key = ProjectRoot::normalize($root);
        $fingerprint = $this->fingerprint->compute($key);
        $entry = $this->entries[$key] ?? null;

        if ($entry !== null && $entry['fingerprint'] === $fingerprint) {
            unset($this->entries[$key]);
            $this->entries[$key] = $entry;

            return $entry['index'];
        }

        $index = $this->inner->build($key);
        unset($this->entries[$key]);
        $this->entries[$key] = ['fingerprint' => $fingerprint, 'index' => $index];

        while (count($this->entries) > $this->maxEntries) {
            unset($this->entries[array_key_first($this->entries)]);
        }

        return $index;
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
