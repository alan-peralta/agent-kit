<?php

namespace Peralta\AgentKit\Tests\Feature\Mcp;

use Illuminate\Support\Facades\Artisan;
use Peralta\AgentKit\Tests\TestCase;

final class McpServeCommandTest extends TestCase
{
    private const REFUSAL = 'The Streamable HTTP transport is served by your application at /mcp when AGENT_KIT_MCP_HTTP_ENABLED=true; start it with php artisan serve or your web server. See MCP_SERVER.md.';

    public function test_the_http_transport_option_is_refused_with_the_documented_message(): void
    {
        self::assertSame(1, Artisan::call('agent-kit:mcp', ['--transport' => 'http', '--path' => $this->fixtureRoot()]));
        self::assertStringContainsString(self::REFUSAL, Artisan::output());
    }

    public function test_the_http_transport_from_config_and_no_option_is_refused_with_the_documented_message(): void
    {
        $this->app['config']->set('agent-kit.mcp.transport', 'http');

        self::assertSame(1, Artisan::call('agent-kit:mcp', ['--path' => $this->fixtureRoot()]));
        self::assertStringContainsString(self::REFUSAL, Artisan::output());
    }

    public function test_an_empty_http_path_is_reported_as_the_default_path(): void
    {
        $this->app['config']->set('agent-kit.mcp.http.path', '');

        self::assertSame(1, Artisan::call('agent-kit:mcp', ['--transport' => 'http', '--path' => $this->fixtureRoot()]));
        self::assertStringContainsString(self::REFUSAL, Artisan::output());
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Refactoring/Ast';
    }
}
