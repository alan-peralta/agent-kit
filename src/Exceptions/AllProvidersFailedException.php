<?php

namespace Peralta\AgentKit\Exceptions;

class AllProvidersFailedException extends AgentException
{
    public function __construct(
        string $message,
        public readonly array $providerSummary = [],
    ) {
        parent::__construct($message);
    }
}
