<?php

namespace Peralta\AgentKit\Tests\Unit;

use Mockery;
use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Contracts\Provider;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Exceptions\MaxIterationsExceededException;
use Peralta\AgentKit\Tests\TestCase;

class AgentTest extends TestCase
{
    public function test_send_runs_multiple_iterations_for_tool_calls_then_returns_final_response()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'echo', ['x' => 1])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant('resposta final'),
                stopReason: 'stop',
                inputTokens: 5,
                outputTokens: 2,
            ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $response = $agent->provider('openai')->tools([$this->echoTool()])->send('oi');

        $this->assertSame('resposta final', $response->text());
        $this->assertSame('stop', $response->stopReason);
    }

    public function test_send_throws_max_iterations_exceeded_when_provider_keeps_requesting_tools()
    {
        config(['agent-kit.safety.max_iterations' => 2]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')->andReturn(new AgentResponse(
            message: Message::assistant(null, [new ToolCall('call-1', 'echo', [])]),
            stopReason: 'tool_use',
        ));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);

        $this->expectException(MaxIterationsExceededException::class);
        $agent->provider('openai')->tools([$this->echoTool()])->send('oi');
    }

    public function test_tool_not_found_returns_error_payload_without_throwing()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'inexistente', [])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($messages) {
                $toolResult = end($messages);
                $this->assertSame('tool', $toolResult->role);
                $this->assertStringContainsString('não está disponível', $toolResult->content);
                return new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop');
            });

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->send('oi'); // no tools registered — 'inexistente' won't be found
    }

    public function test_tool_not_authorized_returns_error_payload_without_calling_handle()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'restrita', [])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($messages) {
                $toolResult = end($messages);
                $this->assertStringContainsString('não tem permissão', $toolResult->content);
                return new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop');
            });

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $tool = new class implements Tool {
            public function name(): string { return 'restrita'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function authorize(Context $context): bool { return false; }
            public function handle(array $input, Context $context): mixed {
                throw new \RuntimeException('handle() não deveria ser chamado');
            }
        };

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->tools([$tool])->send('oi');
    }

    public function test_tool_handle_exception_is_caught_and_returns_error_payload()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'quebra', [])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($messages) {
                $toolResult = end($messages);
                $this->assertStringContainsString('Erro executando', $toolResult->content);
                $this->assertStringContainsString('boom', $toolResult->content);
                return new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop');
            });

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $tool = new class implements Tool {
            public function name(): string { return 'quebra'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed {
                throw new \RuntimeException('boom');
            }
        };

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->tools([$tool])->send('oi');
    }

    public function test_fluent_builder_methods_affect_the_provider_call()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                'seja conciso',
                Mockery::on(fn($opts) => $opts['model'] === 'gpt-4o-mini' && $opts['temperature'] === 0.2),
            )
            ->andReturn(new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop'));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')
            ->model('gpt-4o-mini')
            ->system('seja conciso')
            ->options(['temperature' => 0.2])
            ->send('oi');
    }

    public function test_context_method_accepts_array_and_is_available_to_tools()
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(
                message: Message::assistant(null, [new ToolCall('call-1', 'ver_contexto', [])]),
                stopReason: 'tool_use',
            ));
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop'));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $receivedTenantId = null;
        $tool = new class implements Tool {
            public $onHandle;
            public function name(): string { return 'ver_contexto'; }
            public function description(): string { return 'x'; }
            public function schema(): array { return []; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed {
                ($this->onHandle)($context);
                return [];
            }
        };
        $tool->onHandle = function (Context $context) use (&$receivedTenantId) {
            $receivedTenantId = $context->tenantId;
        };

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->tools([$tool])->context(['tenant_id' => 'tenant-123'])->send('oi');

        $this->assertSame('tenant-123', $receivedTenantId);
    }

    public function test_knowledge_base_adds_knowledge_search_tool_to_resolved_tools()
    {
        config([
            'agent-kit.knowledge.embedder' => 'openai',
            'agent-kit.knowledge.embedders.openai' => ['driver' => 'openai', 'api_key' => 'x', 'model' => 'text-embedding-3-small', 'base_url' => 'https://api.openai.com/v1'],
            'agent-kit.knowledge.store' => 'pgvector',
            'agent-kit.knowledge.stores.pgvector' => ['driver' => 'pgvector', 'connection' => 'pgsql', 'table' => 'knowledge_chunks'],
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $provider->shouldReceive('chat')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(function ($tools) {
                    return count($tools) === 1
                        && $tools[0] instanceof \Peralta\AgentKit\Knowledge\Tools\KnowledgeSearchTool;
                }),
                Mockery::any(),
                Mockery::any(),
            )
            ->andReturn(new AgentResponse(message: Message::assistant('ok'), stopReason: 'stop'));

        $this->app->bind('agent-kit.provider.openai', fn() => $provider);

        $agent = $this->app->make(Agent::class);
        $agent->provider('openai')->knowledgeBase('faq', 'Perguntas frequentes')->send('oi');
    }

    private function echoTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'echo'; }
            public function description(): string { return 'ecoa entrada'; }
            public function schema(): array { return ['type' => 'object']; }
            public function authorize(Context $context): bool { return true; }
            public function handle(array $input, Context $context): mixed { return $input; }
        };
    }
}
