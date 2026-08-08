<?php

namespace Peralta\AgentKit;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Peralta\AgentKit\Contracts\Provider;
use Peralta\AgentKit\Contracts\Tool;
use Peralta\AgentKit\Conversation\ConversationManager;
use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Context;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Pipeline;
use Peralta\AgentKit\Exceptions\AgentException;
use Peralta\AgentKit\Exceptions\MaxIterationsExceededException;
use Peralta\AgentKit\Exceptions\ToolException;
use Peralta\AgentKit\Knowledge\Contracts\Embedder;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\Tools\KnowledgeSearchTool;

class Agent
{
    protected ?string $providerName = null;
    protected ?string $model = null;
    protected ?string $systemPrompt = null;
    protected array $tools = [];
    protected array $knowledgeBases = [];
    protected ?Context $context = null;
    protected array $options = [];
    protected ?string $conversationId = null;
    protected int $maxIterations;

    public function __construct(
        protected Container $container,
        protected array $config,
        protected Pipeline $pipeline,
    ) {
        $this->maxIterations = $config['safety']['max_iterations'] ?? 10;
    }

    public static function make(): self
    {
        return app(self::class);
    }

    public function provider(string $name): self
    {
        $this->providerName = $name;
        return $this;
    }

    public function model(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function system(string $prompt): self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * Adiciona tools (classes ou instâncias).
     */
    public function tools(array $tools): self
    {
        foreach ($tools as $tool) {
            $this->tools[] = is_string($tool) ? $this->container->make($tool) : $tool;
        }
        return $this;
    }

    /**
     * Plugа automaticamente uma tool de RAG na coleção indicada.
     */
    public function knowledgeBase(string $collection, ?string $description = null): self
    {
        $description ??= $this->config['knowledge']['collections'][$collection]['description']
            ?? "Consulta a base de conhecimento '{$collection}'.";

        $this->knowledgeBases[] = compact('collection', 'description');
        return $this;
    }

    public function context(array|Context $ctx): self
    {
        $this->context = is_array($ctx) ? Context::fromArray($ctx) : $ctx;
        return $this;
    }

    public function options(array $opts): self
    {
        $this->options = array_merge($this->options, $opts);
        return $this;
    }

    public function conversation(string $id): self
    {
        $this->conversationId = $id;
        return $this;
    }

    /**
     * Envia uma mensagem e roda o loop até resposta final ou estouro.
     */
    public function send(string $userMessage): AgentResponse
    {
        $provider = $this->resolveProvider();
        $tools = $this->resolveTools();
        $context = $this->context ?? new Context();
        $convo = $this->resolveConversation();

        // Carrega histórico (se houver) e adiciona nova mensagem do usuário
        $messages = $convo && $this->conversationId ? $convo->load($this->conversationId) : [];

        $userMsg = Message::user($userMessage);
        $messages[] = $userMsg;
        if ($convo && $this->conversationId) {
            $convo->append($this->conversationId, $userMsg);
        }

        $iteration = 0;
        while (true) {
            if (++$iteration > $this->maxIterations) {
                throw new MaxIterationsExceededException(
                    "Limite de {$this->maxIterations} iterações atingido."
                );
            }

            $providerName = $this->providerName ?? $this->config['default'];
            $recoveryContext = new RecoveryContext(provider: $providerName);

            $response = $this->pipeline->execute(
                function (RecoveryContext $ctx) use ($messages, $tools) {
                    $provider = $this->container->make("agent-kit.provider.{$ctx->provider}");

                    return $provider->chat(
                        messages: $messages,
                        tools: $tools,
                        system: $this->systemPrompt,
                        options: $this->resolveOptions(),
                    );
                },
                $recoveryContext,
            );

            $provider = $this->container->make("agent-kit.provider.{$recoveryContext->provider}");

            $messages[] = $response->message;
            if ($convo && $this->conversationId) {
                $convo->append($this->conversationId, $response->message);
            }

            if (!$response->hasToolCalls()) {
                $this->logUsage($provider, $response);
                return $response;
            }

            // Executa cada tool pedida
            foreach ($response->toolCalls() as $call) {
                $tool = $this->findTool($tools, $call->name);

                $result = $this->executeTool($tool, $call, $context);

                $toolResultMsg = Message::tool($call->id, $result);
                $messages[] = $toolResultMsg;
                if ($convo && $this->conversationId) {
                    $convo->append($this->conversationId, $toolResultMsg);
                }
            }
        }
    }

    protected function executeTool(?Tool $tool, $call, Context $context): mixed
    {
        if (!$tool) {
            $this->logToolCall($call->name, $call->arguments, '[tool não encontrada]', false);
            return [
                'erro' => "Tool '{$call->name}' não está disponível.",
            ];
        }

        if (!$tool->authorize($context)) {
            $this->logToolCall($call->name, $call->arguments, '[não autorizado]', false);
            return [
                'erro' => "Você não tem permissão para usar a ferramenta '{$call->name}'.",
            ];
        }

        try {
            $result = $tool->handle($call->arguments, $context);
            $this->logToolCall($call->name, $call->arguments, $result, true);
            return $result;
        } catch (\Throwable $e) {
            $this->logToolCall($call->name, $call->arguments, $e->getMessage(), false);
            return [
                'erro' => "Erro executando '{$call->name}': " . $e->getMessage(),
            ];
        }
    }

    /**
     * Retorna lista final de tools (manuais + geradas pelas knowledge bases).
     *
     * @return Tool[]
     */
    protected function resolveTools(): array
    {
        $tools = $this->tools;

        if (!empty($this->knowledgeBases)) {
            $embedder = $this->container->make(Embedder::class);
            $store = $this->container->make(KnowledgeStore::class);

            foreach ($this->knowledgeBases as $kb) {
                $tools[] = new KnowledgeSearchTool(
                    collection: $kb['collection'],
                    description: $kb['description'],
                    embedder: $embedder,
                    store: $store,
                    limit: $this->config['knowledge']['default_limit'] ?? 5,
                    minRelevance: $this->config['knowledge']['min_relevance'] ?? 0.65,
                );
            }
        }

        return $tools;
    }

    protected function findTool(array $tools, string $name): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool->name() === $name) return $tool;
        }
        return null;
    }

    protected function resolveProvider(): Provider
    {
        $name = $this->providerName ?? $this->config['default'];
        return $this->container->make("agent-kit.provider.{$name}");
    }

    protected function resolveConversation(): ?ConversationManager
    {
        if (!$this->conversationId) return null;
        return $this->container->make(ConversationManager::class);
    }

    protected function resolveOptions(): array
    {
        $opts = $this->options;
        if ($this->model) $opts['model'] = $this->model;
        return $opts;
    }

    protected function logUsage(Provider $provider, AgentResponse $response): void
    {
        if (!($this->config['logging']['enabled'] ?? false)) return;

        Log::channel($this->config['logging']['channel'] ?? 'stack')->info(
            '[agent-kit] Resposta finalizada',
            [
                'provider' => $provider->name(),
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'conversation_id' => $this->conversationId,
            ],
        );
    }

    protected function logToolCall(string $name, array $input, mixed $result, bool $success): void
    {
        if (!($this->config['logging']['enabled'] ?? false)) return;
        if (!($this->config['logging']['log_tool_calls'] ?? false)) return;

        Log::channel($this->config['logging']['channel'] ?? 'stack')->info(
            '[agent-kit] Tool executada',
            [
                'tool' => $name,
                'success' => $success,
                'input' => $input,
                'conversation_id' => $this->conversationId,
            ],
        );
    }
}
