<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerTokenAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthorizationTokenValidatorInterface $validator,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only the Authorization header is consulted; query strings and cookies are never read.
        $authorization = trim($request->getHeaderLine('Authorization'));
        if ($authorization === '') {
            return $this->deny(AuthorizationResult::unauthorized(null, 'Bearer token required.'));
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) !== 1) {
            return $this->deny(AuthorizationResult::badRequest('invalid_request', 'Malformed Authorization header.'));
        }

        $result = $this->validator->validate($matches[1]);
        if (!$result->isAllowed()) {
            return $this->deny($result);
        }

        foreach ($result->getAttributes() as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $handler->handle($request);
    }

    private function deny(AuthorizationResult $result): ResponseInterface
    {
        $challenge = 'Bearer';
        if ($result->getError() !== null) {
            $challenge .= ' error="' . $result->getError() . '"';
        }

        $body = json_encode([
            'error' => $result->getError() ?? 'unauthorized',
            'message' => $result->getErrorDescription() ?? 'Authentication required.',
        ], JSON_THROW_ON_ERROR);

        return $this->responses->createResponse($result->getStatusCode())
            ->withHeader('WWW-Authenticate', $challenge)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($body));
    }
}
