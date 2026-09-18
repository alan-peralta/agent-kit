<?php

namespace Peralta\AgentKit\Refactoring\Mcp\Transport\Http;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LaravelPsrBridge
{
    private const CHUNK_BYTES = 8192;

    public static function toPsrRequest(Request $request): ServerRequestInterface
    {
        return (new ServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->headers->all(),
            Utils::streamFor($request->getContent(true)),
            str_replace('HTTP/', '', (string) $request->getProtocolVersion()) ?: '1.1',
            $request->server->all(),
        ))->withQueryParams($request->query->all());
    }

    public static function toLaravelResponse(ResponseInterface $response): Response
    {
        $body = $response->getBody();
        if ($body->getSize() !== null) {
            return new Response((string) $body, $response->getStatusCode(), $response->getHeaders());
        }

        // An unknown size means a stream the SDK is still writing (SSE); pass it through as it comes.
        return new StreamedResponse(static function () use ($body): void {
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                flush();
            }
        }, $response->getStatusCode(), $response->getHeaders());
    }
}
