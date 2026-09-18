<?php

namespace Peralta\AgentKit\ErrorRecovery\Classifiers;

use GuzzleHttp\Exception\RequestException;
use Peralta\AgentKit\ErrorRecovery\Contracts\ErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class DefaultErrorClassifier implements ErrorClassifier
{
    public function classify(Throwable $error): ErrorType
    {
        $previous = $error->getPrevious();

        // Guzzle 7's ConnectException and Guzzle 8's NetworkException family: no response arrived.
        if ($previous instanceof NetworkExceptionInterface) {
            return ErrorType::NETWORK_TIMEOUT;
        }

        $response = $previous === null ? null : $this->responseOf($previous);
        if ($response !== null) {
            return $this->classifyStatusCode($response->getStatusCode());
        }

        if ($previous instanceof RequestException) {
            // No response: a connection timeout or a network error.
            return ErrorType::NETWORK_TIMEOUT;
        }

        return ErrorType::SERVER_ERROR;
    }

    /**
     * Guzzle 7 exposes the response on RequestException (null when there is none) and Guzzle 8
     * only on ResponseException, so ask the exception itself instead of naming either class.
     */
    private function responseOf(Throwable $exception): ?ResponseInterface
    {
        if (!method_exists($exception, 'getResponse')) {
            return null;
        }

        try {
            $response = $exception->getResponse();
        } catch (Throwable) {
            return null;
        }

        return $response instanceof ResponseInterface ? $response : null;
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
