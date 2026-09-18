<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

final class StaticBearerTokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(private readonly string $expectedToken)
    {
        if ($expectedToken === '') {
            throw new InvalidArgumentException('The expected bearer token must not be empty.');
        }
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        if (!hash_equals($this->expectedToken, $accessToken)) {
            return AuthorizationResult::unauthorized('invalid_token', 'The bearer token is not valid.');
        }

        return AuthorizationResult::allow(['auth.scheme' => 'bearer']);
    }
}
