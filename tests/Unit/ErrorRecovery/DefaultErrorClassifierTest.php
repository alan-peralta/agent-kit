<?php

namespace Peralta\AgentKit\Tests\Unit\ErrorRecovery;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Enums\ErrorType;
use Peralta\AgentKit\Exceptions\ProviderException;
use Peralta\AgentKit\Tests\TestCase;

class DefaultErrorClassifierTest extends TestCase
{
    private DefaultErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new DefaultErrorClassifier();
    }

    public function test_connect_exception_is_network_timeout()
    {
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions');
        $previous = new ConnectException('Connection timed out', $request);
        $error = new ProviderException('Erro chamando openai: Connection timed out', 0, $previous);

        $this->assertEquals(ErrorType::NETWORK_TIMEOUT, $this->classifier->classify($error));
    }

    public function test_429_is_rate_limit()
    {
        $error = $this->providerExceptionWithStatus(429);
        $this->assertEquals(ErrorType::RATE_LIMIT, $this->classifier->classify($error));
    }

    public function test_400_is_invalid_request()
    {
        $error = $this->providerExceptionWithStatus(400);
        $this->assertEquals(ErrorType::INVALID_REQUEST, $this->classifier->classify($error));
    }

    public function test_401_is_auth_error()
    {
        $error = $this->providerExceptionWithStatus(401);
        $this->assertEquals(ErrorType::AUTH_ERROR, $this->classifier->classify($error));
    }

    public function test_403_is_auth_error()
    {
        $error = $this->providerExceptionWithStatus(403);
        $this->assertEquals(ErrorType::AUTH_ERROR, $this->classifier->classify($error));
    }

    public function test_500_is_server_error()
    {
        $error = $this->providerExceptionWithStatus(500);
        $this->assertEquals(ErrorType::SERVER_ERROR, $this->classifier->classify($error));
    }

    public function test_unknown_throwable_defaults_to_server_error()
    {
        $error = new \RuntimeException('something weird');
        $this->assertEquals(ErrorType::SERVER_ERROR, $this->classifier->classify($error));
    }

    private function providerExceptionWithStatus(int $status): ProviderException
    {
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions');
        $response = new Response($status, [], '{"error":"boom"}');
        $previous = new RequestException('HTTP error', $request, $response);

        return new ProviderException("Erro chamando openai: HTTP error", 0, $previous);
    }
}
