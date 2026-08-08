<?php

namespace Peralta\AgentKit\ErrorRecovery\Contracts;

use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Throwable;

interface ErrorClassifier
{
    public function classify(Throwable $error): ErrorType;
}
