<?php

namespace Peralta\AgentKit;

use Illuminate\Support\ServiceProvider;
use Peralta\AgentKit\Conversation\ConversationManager;
use Peralta\AgentKit\Conversation\Contracts\ConversationStore;
use Peralta\AgentKit\Conversation\Drivers\ArrayStore;
use Peralta\AgentKit\Conversation\Drivers\DatabaseStore;
use Peralta\AgentKit\Conversation\Drivers\HybridStore;
use Peralta\AgentKit\Conversation\Drivers\RedisStore;
use Peralta\AgentKit\Knowledge\Contracts\Embedder;
use Peralta\AgentKit\Knowledge\Contracts\KnowledgeStore;
use Peralta\AgentKit\Knowledge\Embedders\AwsTitanEmbedder;
use Peralta\AgentKit\Knowledge\Embedders\CohereEmbedder;
use Peralta\AgentKit\Knowledge\Embedders\GeminiEmbedder;
use Peralta\AgentKit\Knowledge\Embedders\MistralEmbedder;
use Peralta\AgentKit\Knowledge\Embedders\OpenAIEmbedder;
use Peralta\AgentKit\Knowledge\KnowledgeIndexer;
use Peralta\AgentKit\Knowledge\Stores\PgvectorStore;
use Peralta\AgentKit\Knowledge\Stores\QdrantStore;
use Peralta\AgentKit\Providers\AnthropicProvider;
use Peralta\AgentKit\Providers\DeepSeekProvider;
use Peralta\AgentKit\Providers\GeminiProvider;
use Peralta\AgentKit\Providers\OpenAIProvider;

class AgentKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/agent-kit.php', 'agent-kit');

        $this->registerProviders();
        $this->registerConversation();
        $this->registerKnowledge();
        $this->registerAgent();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/agent-kit.php' => config_path('agent-kit.php'),
            ], 'agent-kit-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'agent-kit-migrations');
        }
    }

    protected function registerProviders(): void
    {
        $providers = [
            'openai' => OpenAIProvider::class,
            'anthropic' => AnthropicProvider::class,
            'gemini' => GeminiProvider::class,
            'deepseek' => DeepSeekProvider::class,
        ];

        foreach ($providers as $name => $class) {
            $this->app->bind("agent-kit.provider.{$name}", function ($app) use ($name, $class) {
                $config = config("agent-kit.providers.{$name}");
                if (!$config) {
                    throw new \RuntimeException("Provider '{$name}' não configurado.");
                }
                return new $class($config);
            });
        }
    }

    protected function registerConversation(): void
    {
        $this->app->bind(ConversationStore::class, function ($app) {
            $driver = config('agent-kit.conversation.driver', 'array');
            $cfg = config("agent-kit.conversation.drivers.{$driver}", []);

            return match ($driver) {
                'array' => new ArrayStore(),
                'database' => new DatabaseStore(
                    connection: $cfg['connection'] ?? null,
                    messagesTable: $cfg['messages_table'] ?? 'agent_messages',
                ),
                'redis' => new RedisStore(
                    connection: $cfg['connection'] ?? 'default',
                    prefix: $cfg['prefix'] ?? 'agent:conv:',
                    ttl: $cfg['ttl'] ?? 2592000,
                ),
                'hybrid' => new HybridStore(
                    redisConnection: $cfg['redis_connection'] ?? 'default',
                    redisPrefix: $cfg['redis_prefix'] ?? 'agent:conv:',
                    redisTtl: $cfg['redis_ttl'] ?? 2592000,
                    dbConnection: $cfg['db_connection'] ?? null,
                    messagesTable: $cfg['messages_table'] ?? 'agent_messages',
                ),
                default => throw new \RuntimeException("Driver de conversation '{$driver}' inválido."),
            };
        });

        $this->app->bind(ConversationManager::class, function ($app) {
            return new ConversationManager(
                store: $app->make(ConversationStore::class),
                maxMessages: config('agent-kit.conversation.max_messages', 50),
            );
        });
    }

    protected function registerKnowledge(): void
    {
        $this->app->bind(Embedder::class, function ($app) {
            $name = config('agent-kit.knowledge.embedder', 'openai');
            $cfg = config("agent-kit.knowledge.embedders.{$name}");
            if (!$cfg) {
                throw new \RuntimeException("Embedder '{$name}' não configurado.");
            }

            return match ($cfg['driver']) {
                'openai' => new OpenAIEmbedder($cfg),
                'gemini' => new GeminiEmbedder($cfg),
                'cohere' => new CohereEmbedder($cfg),
                'mistral' => new MistralEmbedder($cfg),
                'aws' => new AwsTitanEmbedder($cfg),
                default => throw new \RuntimeException("Driver de embedder '{$cfg['driver']}' inválido."),
            };
        });

        $this->app->bind(KnowledgeStore::class, function ($app) {
            $name = config('agent-kit.knowledge.store', 'pgvector');
            $cfg = config("agent-kit.knowledge.stores.{$name}");
            if (!$cfg) {
                throw new \RuntimeException("Knowledge store '{$name}' não configurado.");
            }

            return match ($cfg['driver']) {
                'pgvector' => new PgvectorStore(
                    connection: $cfg['connection'] ?? 'pgsql',
                    table: $cfg['table'] ?? 'knowledge_chunks',
                ),
                'qdrant' => new QdrantStore(
                    url: $cfg['url'] ?? '',
                    apiKey: $cfg['api_key'] ?? null,
                    collection: $cfg['collection'] ?? 'knowledge_chunks',
                    timeout: (float) ($cfg['timeout'] ?? 30),
                    batchSize: (int) ($cfg['batch_size'] ?? 100),
                ),
                default => throw new \RuntimeException("Driver de knowledge store '{$cfg['driver']}' inválido."),
            };
        });

        $this->app->bind(KnowledgeIndexer::class, function ($app) {
            return new KnowledgeIndexer(
                embedder: $app->make(Embedder::class),
                store: $app->make(KnowledgeStore::class),
            );
        });
    }

    protected function registerAgent(): void
    {
        $this->app->bind(Agent::class, function ($app) {
            return new Agent(
                container: $app,
                config: config('agent-kit'),
            );
        });
    }
}
