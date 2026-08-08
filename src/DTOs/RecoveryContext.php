<?php

namespace Peralta\AgentKit\DTOs;

class RecoveryContext
{
    /** @var array<string, int> */
    protected array $attempts = [];

    /** @var array<string, string> */
    protected array $lastErrors = [];

    public bool $fallbackUsed = false;

    public function __construct(
        public string $provider,
        public readonly ?string $conversationId = null,
    ) {}

    public function recordAttempt(string $provider): void
    {
        $this->attempts[$provider] = ($this->attempts[$provider] ?? 0) + 1;
    }

    public function recordFailure(string $provider, string $errorType): void
    {
        $this->lastErrors[$provider] = $errorType;
    }

    public function attemptsFor(string $provider): int
    {
        return $this->attempts[$provider] ?? 0;
    }

    public function switchProvider(string $provider): void
    {
        $this->provider = $provider;
        $this->fallbackUsed = true;
    }

    /**
     * @return array<string, array{attempts: int, last_error: string|null}>
     */
    public function summary(): array
    {
        $providers = array_unique(array_merge(
            array_keys($this->attempts),
            array_keys($this->lastErrors),
        ));

        $summary = [];
        foreach ($providers as $provider) {
            $summary[$provider] = [
                'attempts' => $this->attempts[$provider] ?? 0,
                'last_error' => $this->lastErrors[$provider] ?? null,
            ];
        }

        return $summary;
    }
}
