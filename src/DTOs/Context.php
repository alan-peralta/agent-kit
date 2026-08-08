<?php

namespace Peralta\AgentKit\DTOs;

class Context
{
    public function __construct(
        public readonly mixed $tenantId = null,
        public readonly mixed $user = null,
        public readonly array $extra = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: $data['tenant_id'] ?? null,
            user: $data['user'] ?? null,
            extra: array_diff_key($data, ['tenant_id' => null, 'user' => null]),
        );
    }
}
