<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp\Http;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\LaravelPsrBridge;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LaravelPsrBridgeTest extends TestCase
{
    public function test_to_psr_request_keeps_method_full_uri_with_query_headers_protocol_version_server_params_and_body(): void
    {
        $request = Request::create(
            'https://example.test:8443/mcp?foo=bar&baz=qux',
            'POST',
            [],
            [],
            [],
            ['SERVER_PROTOCOL' => 'HTTP/1.1', 'REMOTE_ADDR' => '203.0.113.9'],
            '{"jsonrpc":"2.0","id":1,"method":"initialize"}',
        );
        $request->headers->set('Authorization', 'Bearer test-token');
        $request->headers->set('Mcp-Session-Id', 'abc-123');

        $psr = LaravelPsrBridge::toPsrRequest($request);

        self::assertSame('POST', $psr->getMethod());
        // Symfony's Request::getUri() normalises the query string alphabetically (baz before
        // foo); the scheme, host, port and path travel through unchanged, and getQueryParams()
        // below is what actually carries the query values, in the order the client sent them.
        self::assertSame('https://example.test:8443/mcp', strtok((string) $psr->getUri(), '?'));
        self::assertStringContainsString('foo=bar', (string) $psr->getUri());
        self::assertStringContainsString('baz=qux', (string) $psr->getUri());
        self::assertSame(['foo' => 'bar', 'baz' => 'qux'], $psr->getQueryParams());
        // Not "HTTP/1.1": PSR-7 wants the bare version number.
        self::assertSame('1.1', $psr->getProtocolVersion());
        self::assertSame('Bearer test-token', $psr->getHeaderLine('Authorization'));
        self::assertSame('abc-123', $psr->getHeaderLine('Mcp-Session-Id'));
        self::assertSame('203.0.113.9', $psr->getServerParams()['REMOTE_ADDR']);
        self::assertSame('{"jsonrpc":"2.0","id":1,"method":"initialize"}', (string) $psr->getBody());
    }

    public function test_to_psr_request_defaults_the_protocol_version_when_none_is_present(): void
    {
        $request = Request::create('http://localhost/mcp', 'GET');
        $request->server->remove('SERVER_PROTOCOL');

        $psr = LaravelPsrBridge::toPsrRequest($request);

        self::assertSame('1.1', $psr->getProtocolVersion());
    }

    public function test_to_laravel_response_keeps_status_every_header_value_and_body_for_a_known_size_body(): void
    {
        $response = new PsrResponse(
            201,
            ['X-Multi' => ['one', 'two'], 'Content-Type' => 'application/json'],
            '{"ok":true}',
        );

        $laravel = LaravelPsrBridge::toLaravelResponse($response);

        self::assertInstanceOf(Response::class, $laravel);
        self::assertNotInstanceOf(StreamedResponse::class, $laravel);
        self::assertSame(201, $laravel->getStatusCode());
        self::assertSame(['one', 'two'], $laravel->headers->all('X-Multi'));
        self::assertSame('application/json', $laravel->headers->get('Content-Type'));
        self::assertSame('{"ok":true}', $laravel->getContent());
    }

    public function test_to_laravel_response_streams_a_pump_stream_with_an_unknown_size(): void
    {
        $chunks = ['first-chunk-', 'second-chunk-', 'third'];
        $pump = new PumpStream(static function () use (&$chunks) {
            return array_shift($chunks) ?? false;
        });
        self::assertNull($pump->getSize());

        $response = new PsrResponse(200, ['Content-Type' => 'text/event-stream'], $pump);

        $laravel = LaravelPsrBridge::toLaravelResponse($response);

        self::assertInstanceOf(StreamedResponse::class, $laravel);
        self::assertSame(200, $laravel->getStatusCode());
        self::assertSame('text/event-stream', $laravel->headers->get('Content-Type'));

        ob_start();
        $laravel->sendContent();
        $output = ob_get_clean();

        self::assertSame('first-chunk-second-chunk-third', $output);
    }

    public function test_to_laravel_response_streams_an_fn_stream_with_an_unknown_size(): void
    {
        $body = FnStream::decorate(Utils::streamFor('the whole body, in one piece'), [
            'getSize' => static fn () => null,
        ]);

        $response = new PsrResponse(200, [], $body);
        $laravel = LaravelPsrBridge::toLaravelResponse($response);

        self::assertInstanceOf(StreamedResponse::class, $laravel);

        ob_start();
        $laravel->sendContent();
        $output = ob_get_clean();

        self::assertSame('the whole body, in one piece', $output);
    }

    public function test_to_laravel_response_stops_streaming_and_reports_a_mid_stream_failure_without_letting_it_escape(): void
    {
        $chunks = ['first-chunk-'];
        $body = FnStream::decorate(Utils::streamFor('first-chunk-then-it-breaks'), [
            'getSize' => static fn () => null,
            'eof' => static fn () => false,
            'read' => static function () use (&$chunks) {
                if ($chunks !== []) {
                    return array_shift($chunks);
                }

                throw new RuntimeException('the stream blew up mid-flight, at /secret/path');
            },
        ]);

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{message: string, context: array}> */
            public array $errors = [];

            public function log($level, $message, array $context = []): void
            {
                if ((string) $level === 'error') {
                    $this->errors[] = ['message' => (string) $message, 'context' => $context];
                }
            }
        };
        $response = new PsrResponse(200, [], $body);

        $laravel = LaravelPsrBridge::toLaravelResponse($response, $logger);

        ob_start();
        $laravel->sendContent();
        $output = ob_get_clean();

        self::assertSame('first-chunk-', $output);
        self::assertCount(1, $logger->errors);
        self::assertSame('The MCP HTTP transport failed while streaming a response.', $logger->errors[0]['message']);
        self::assertInstanceOf(RuntimeException::class, $logger->errors[0]['context']['exception']);
    }

    public function test_to_laravel_response_streams_without_a_logger_when_none_is_given(): void
    {
        $body = FnStream::decorate(Utils::streamFor('short'), [
            'getSize' => static fn () => null,
            'eof' => static fn () => false,
            'read' => static function () {
                throw new RuntimeException('fails on the very first read, no logger to tell');
            },
        ]);

        $laravel = LaravelPsrBridge::toLaravelResponse(new PsrResponse(200, [], $body));

        ob_start();
        $laravel->sendContent();
        $output = ob_get_clean();

        self::assertSame('', $output);
    }
}
