<?php

namespace Peralta\AgentKit\Exceptions;

use Throwable;

class AllProvidersFailedException extends AgentException
{
    public function __construct(
        string $message,
        public readonly array $providerSummary = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
