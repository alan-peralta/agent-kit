<?php

namespace Peralta\AgentKit\Refactoring\Application;

use RuntimeException;

final class CapabilityException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return [
            'schema_version' => CapabilityResult::SCHEMA_VERSION,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ];
    }
}
