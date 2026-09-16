<?php

namespace Peralta\AgentKit\Refactoring\Application;

final readonly class CapabilityResult
{
    public const SCHEMA_VERSION = '1.0';

    public function __construct(
        public string $capability,
        public array $data,
        public array $diagnostics = [],
        public array $unresolved = [],
    ) {}

    public function incomplete(): bool
    {
        return $this->diagnostics !== [] || $this->unresolved !== [];
    }

    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $this->capability,
            'incomplete' => $this->incomplete(),
            'data' => $this->normalizeEntries($this->data),
            'diagnostics' => $this->normalizeEntries($this->diagnostics),
            'unresolved' => $this->normalizeEntries($this->unresolved),
        ];
    }

    private function normalizeEntries(array $entries): array
    {
        return array_map(
            static fn (mixed $entry): mixed => is_object($entry) && is_callable([$entry, 'toArray'])
                ? $entry->toArray()
                : $entry,
            $entries,
        );
    }
}
