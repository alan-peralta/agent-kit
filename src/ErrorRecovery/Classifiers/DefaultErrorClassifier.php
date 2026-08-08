<?php

namespace Peralta\AgentKit\ErrorRecovery\Classifiers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Throwable;

class DefaultErrorClassifier implements ErrorClassifier
{
    public function classify(Throwable $error): ErrorType
    {
        $previous = $error->getPrevious();

        if ($previous instanceof ConnectException) {
            return ErrorType::NETWORK_TIMEOUT;
        }

        if ($previous instanceof RequestException && $previous->hasResponse()) {
            return $this->classifyStatusCode($previous->getResponse()->getStatusCode());
        }

        if ($previous instanceof RequestException) {
            // Sem response = timeout de conexão ou erro de rede
            return ErrorType::NETWORK_TIMEOUT;
        }

        return ErrorType::SERVER_ERROR;
    }

    private function classifyStatusCode(int $status): ErrorType
    {
        return match (true) {
            $status === 429 => ErrorType::RATE_LIMIT,
            $status === 400 => ErrorType::INVALID_REQUEST,
            $status === 401, $status === 403 => ErrorType::AUTH_ERROR,
            $status >= 500 => ErrorType::SERVER_ERROR,
            default => ErrorType::SERVER_ERROR,
        };
    }
}
