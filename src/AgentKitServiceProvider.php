<?php

namespace Peralta\AgentKit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Peralta\AgentKit\ErrorRecovery\Classifiers\DefaultErrorClassifier;
use Peralta\AgentKit\ErrorRecovery\Contracts\AlertNotifier;
use Peralta\AgentKit\ErrorRecovery\Middleware\DiscordAlertMiddleware;
use Peralta\AgentKit\ErrorRecovery\Middleware\FallbackMiddleware;
use Peralta\AgentKit\ErrorRecovery\Middleware\RetryMiddleware;
use Peralta\AgentKit\ErrorRecovery\Notifiers\DiscordNotifier;
use Peralta\AgentKit\ErrorRecovery\Notifiers\NullNotifier;
use Peralta\AgentKit\ErrorRecovery\Pipeline;
use Peralta\AgentKit\ErrorRecovery\Strategies\AdaptiveRetryStrategy;
use Peralta\AgentKit\Events\AgentKitEvent;
use Peralta\AgentKit\Events\TokenUsageRecorded;
use Peralta\AgentKit\Events\ToolCallExecuted;
use Peralta\AgentKit\Listeners\LogToolCallListener;
use Peralta\AgentKit\Listeners\LogUsageListener;
use Peralta\AgentKit\Listeners\PersistMetricsListener;
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
use Peralta\AgentKit\Knowledge\Stores\DatabaseVectorStore;
use Peralta\AgentKit\Knowledge\Stores\PgvectorStore;
use Peralta\AgentKit\Knowledge\Stores\QdrantStore;
use Peralta\AgentKit\Providers\AnthropicProvider;
use Peralta\AgentKit\Providers\DeepSeekProvider;
use Peralta\AgentKit\Providers\GeminiProvider;
use Peralta\AgentKit\Providers\OpenAIProvider;
use Peralta\AgentKit\Refactoring\Analysis\Ast\AstParser;
use Peralta\AgentKit\Refactoring\Analysis\Ast\PhpAstParser;
use Peralta\AgentKit\Refactoring\Analysis\CallerAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\ImpactAnalyzer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CachedCodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexBuilder;
use Peralta\AgentKit\Refactoring\Analysis\Index\CodebaseIndexer;
use Peralta\AgentKit\Refactoring\Analysis\Index\IndexSnapshotStore;
use Peralta\AgentKit\Refactoring\Analysis\Index\ProjectFingerprint;
use Peralta\AgentKit\Refactoring\Agents\AgentAdapterRegistry;
use Peralta\AgentKit\Refactoring\Agents\AgentCommandRepository;
use Peralta\AgentKit\Refactoring\Agents\AgentConfigurationInstaller;
use Peralta\AgentKit\Refactoring\Agents\AgentTemplateRenderer;
use Peralta\AgentKit\Refactoring\Agents\ClaudeCodeAgentAdapter;
use Peralta\AgentKit\Refactoring\Agents\CursorAgentAdapter;
use Peralta\AgentKit\Refactoring\Application\DefaultRefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Peralta\AgentKit\Refactoring\Commands\InstallAgentsCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorAnalyzeCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorAuditCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorCallersCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorCapabilitiesCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorDependenciesCommand;
use Peralta\AgentKit\Refactoring\Commands\RefactorImpactCommand;
use Peralta\AgentKit\Refactoring\Mcp\Commands\McpServeCommand;
use Peralta\AgentKit\Refactoring\Mcp\McpLoggerFactory;
use Peralta\AgentKit\Refactoring\Mcp\McpServerFactory;
use Peralta\AgentKit\Refactoring\Mcp\RefactoringToolCatalog;
use Peralta\AgentKit\Refactoring\Mcp\Transport\StdioServerRunner;
use Peralta\AgentKit\Refactoring\Support\PhpFileAnalyzer;
use Peralta\AgentKit\Refactoring\Support\ProjectScanner;
use Peralta\AgentKit\Refactoring\Support\RefactoringReport;

class AgentKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/agent-kit.php', 'agent-kit');

        $this->registerProviders();
        $this->registerConversation();
        $this->registerKnowledge();
        $this->registerErrorRecovery();
        $this->registerAgent();
        $this->registerAnalytics();
        $this->registerRefactoring();
        $this->registerMcp();
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

            // Knowledge tables have one tag per store, so `migrate` only needs the database the chosen store uses.
            $this->publishes([
                __DIR__ . '/../database/knowledge/pgvector' => database_path('migrations'),
            ], 'agent-kit-pgvector-migrations');

            $this->publishes([
                __DIR__ . '/../database/knowledge/database' => database_path('migrations'),
            ], 'agent-kit-database-store-migrations');

            $this->commands([
                InstallAgentsCommand::class,
                RefactorAuditCommand::class,
                RefactorAnalyzeCommand::class,
                RefactorCapabilitiesCommand::class,
                RefactorCallersCommand::class,
                RefactorDependenciesCommand::class,
                RefactorImpactCommand::class,
                McpServeCommand::class,
            ]);
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
            if (!$cfg && $name === 'database') {
                // Configs published before this store existed have no entry for it; its defaults need none.
                $cfg = ['driver' => 'database'];
            }
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
                'database' => new DatabaseVectorStore(
                    connection: $cfg['connection'] ?? null,
                    table: $cfg['table'] ?? 'knowledge_chunks',
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

    protected function registerErrorRecovery(): void
    {
        $this->app->bind(AlertNotifier::class, function ($app) {
            if (!config('agent-kit.error_recovery.alerts.enabled', false)) {
                return new NullNotifier();
            }

            return new DiscordNotifier(
                webhookUrl: config('agent-kit.error_recovery.alerts.discord.webhook_url', ''),
                mention: config('agent-kit.error_recovery.alerts.discord.mention_on_critical'),
            );
        });

        $this->app->bind(Pipeline::class, function ($app) {
            $classifier = new DefaultErrorClassifier();
            $strategy = new AdaptiveRetryStrategy(
                config('agent-kit.error_recovery.retry.policies', []),
            );

            $providerNames = array_keys(config('agent-kit.providers', []));

            return new Pipeline([
                new DiscordAlertMiddleware($app->make(AlertNotifier::class)),
                new FallbackMiddleware(
                    $classifier,
                    $providerNames,
                    config('agent-kit.error_recovery.fallback', []),
                ),
                new RetryMiddleware($strategy, $classifier),
            ]);
        });
    }

    protected function registerAgent(): void
    {
        $this->app->bind(Agent::class, function ($app) {
            return new Agent(
                container: $app,
                config: config('agent-kit'),
                pipeline: $app->make(Pipeline::class),
            );
        });
    }

    protected function registerRefactoring(): void
    {
        $this->app->bind(PhpFileAnalyzer::class, fn () => new PhpFileAnalyzer(
            config('agent-kit.refactoring.thresholds', []),
        ));

        $this->app->bind(ProjectScanner::class, fn ($app) => new ProjectScanner(
            analyzer: $app->make(PhpFileAnalyzer::class),
            excludedDirectories: config('agent-kit.refactoring.exclude', []),
        ));

        $this->app->singleton(RefactoringReport::class);

        $this->app->bind(AstParser::class, fn () => new PhpAstParser(
            config('agent-kit.refactoring.facades', ['Illuminate\\Support\\Facades\\']),
        ));
        $this->app->bind(CodebaseIndexer::class, fn ($app) => new CodebaseIndexer(
            $app->make(ProjectScanner::class),
            $app->make(AstParser::class),
        ));
        $this->app->singleton(ProjectFingerprint::class, fn ($app) => new ProjectFingerprint(
            $app->make(ProjectScanner::class),
        ));
        // One cache per process: the MCP server keeps it for its whole life, the CLI for one command.
        $this->app->singleton(CachedCodebaseIndexer::class, function ($app) {
            $path = config('agent-kit.mcp.index_cache.path');
            $snapshots = $path === '' ? null : new IndexSnapshotStore($path ?? storage_path('framework/cache/agent-kit/index'));

            return new CachedCodebaseIndexer(
                $app->make(CodebaseIndexer::class),
                $app->make(ProjectFingerprint::class),
                max(1, (int) config('agent-kit.mcp.index_cache.max_entries', 1)),
                $snapshots,
            );
        });
        $this->app->bind(CodebaseIndexBuilder::class, fn ($app) => $app->make(CachedCodebaseIndexer::class));
        $this->app->singleton(CallerAnalyzer::class);
        $this->app->bind(ImpactAnalyzer::class, fn () => new ImpactAnalyzer(
            config('agent-kit.refactoring.impact_thresholds', []),
        ));
        $this->app->bind(RefactoringCapabilities::class, fn ($app) => new DefaultRefactoringCapabilities(
            $app->make(ProjectScanner::class),
            $app->make(PhpFileAnalyzer::class),
            $app->make(RefactoringReport::class),
            $app->make(CodebaseIndexBuilder::class),
            $app->make(CallerAnalyzer::class),
            $app->make(ImpactAnalyzer::class),
        ));

        $this->app->singleton(AgentCommandRepository::class, fn () => new AgentCommandRepository(
            __DIR__ . '/../resources/agents/refactoring',
        ));
        $this->app->singleton(AgentTemplateRenderer::class);
        $this->app->singleton(AgentAdapterRegistry::class, fn () => new AgentAdapterRegistry([
            new CursorAgentAdapter(),
            new ClaudeCodeAgentAdapter(),
        ]));
        $this->app->singleton(AgentConfigurationInstaller::class, fn ($app) => new AgentConfigurationInstaller(
            $app->make(AgentAdapterRegistry::class),
            $app->make(AgentCommandRepository::class),
            $app->make(AgentTemplateRenderer::class),
        ));
    }

    protected function registerAnalytics(): void
    {
        Event::listen(TokenUsageRecorded::class, LogUsageListener::class);
        Event::listen(ToolCallExecuted::class, LogToolCallListener::class);
        Event::listen(AgentKitEvent::class, PersistMetricsListener::class);
    }

    protected function registerMcp(): void
    {
        $this->app->singleton(RefactoringToolCatalog::class);
        $this->app->singleton(McpLoggerFactory::class, fn ($app) => new McpLoggerFactory($app->make('log')));
        $this->app->bind(McpServerFactory::class, fn ($app) => new McpServerFactory(
            $app->make(RefactoringCapabilities::class),
            $app->make(RefactoringToolCatalog::class),
        ));
        $this->app->bind(StdioServerRunner::class, fn ($app) => new StdioServerRunner(
            $app->make(McpServerFactory::class),
            $app->make(CachedCodebaseIndexer::class),
        ));
    }
}
