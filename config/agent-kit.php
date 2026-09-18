<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Provider padrão
    |--------------------------------------------------------------------------
    | Provider de LLM usado quando nenhum é especificado no Agent.
    | Opções: openai, anthropic, gemini, deepseek
    */
    'default' => env('AGENT_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Providers disponíveis
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => 4096,
            'timeout' => 60,
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-5'),
            'anthropic_version' => '2023-06-01',
            'max_tokens' => 4096,
            'timeout' => 60,
        ],

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            'max_tokens' => 4096,
            'timeout' => 60,
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'api_key' => env('DEEPSEEK_API_KEY'),
            // DeepSeek é compatível com a API da OpenAI
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'max_tokens' => 4096,
            'timeout' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limites de segurança do loop
    |--------------------------------------------------------------------------
    */
    'safety' => [
        // Quantas iterações de tool calls antes de abortar
        'max_iterations' => 10,
        // Timeout máximo em segundos pra execução de uma tool
        'tool_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistência de conversas
    |--------------------------------------------------------------------------
    | Drivers: array (não persiste), database, redis, hybrid (redis + database)
    */
    'conversation' => [
        'driver' => env('AGENT_CONVERSATION_DRIVER', 'array'),

        'drivers' => [
            'array' => [
                'driver' => 'array',
            ],
            'database' => [
                'driver' => 'database',
                'connection' => env('AGENT_CONVERSATION_DB', null), // null = default
                'messages_table' => 'agent_messages',
            ],
            'redis' => [
                'driver' => 'redis',
                'connection' => env('AGENT_CONVERSATION_REDIS', 'default'),
                'prefix' => 'agent:conv:',
                'ttl' => 60 * 60 * 24 * 30, // 30 dias
            ],
            'hybrid' => [
                'driver' => 'hybrid',
                // Redis (cache quente/performance)
                'redis_connection' => env('AGENT_CONVERSATION_REDIS', 'default'),
                'redis_prefix' => env('AGENT_CONVERSATION_REDIS_PREFIX', 'agent:conv:'),
                'redis_ttl' => env('AGENT_CONVERSATION_REDIS_TTL', 60 * 60 * 24 * 30), // 30 dias por padrão
                // Database (auditoria/histórico permanente)
                'db_connection' => env('AGENT_CONVERSATION_DB', null), // null = default
                'messages_table' => 'agent_messages',
            ],
        ],

        // Limite máximo de mensagens mantidas (truncamento automático)
        'max_messages' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge Base / RAG
    |--------------------------------------------------------------------------
    */
    'knowledge' => [
        'embedder' => env('AGENT_EMBEDDER', 'openai'),

        'embedders' => [
            'openai' => [
                'driver' => 'openai',
                'api_key' => env('OPENAI_API_KEY'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model' => env('OPENAI_EMBEDDER_MODEL', 'text-embedding-3-small'),
                'dimensions' => env('OPENAI_EMBEDDER_DIMENSIONS', 1536),
            ],
            'gemini' => [
                'driver' => 'gemini',
                'api_key' => env('GEMINI_API_KEY'),
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                // Gemini embedding-001 sempre retorna 768 dimensões
            ],
            'cohere' => [
                'driver' => 'cohere',
                'api_key' => env('COHERE_API_KEY'),
                'base_url' => env('COHERE_BASE_URL', 'https://api.cohere.com'),
                'model' => env('COHERE_EMBEDDER_MODEL', 'embed-english-v3.0'),
                'input_type' => env('COHERE_EMBEDDER_INPUT_TYPE', 'search_document'),
                // embed-english-v3.0 = 1024 dimensões
                // embed-english-light-v3.0 = 384 dimensões
            ],
            'mistral' => [
                'driver' => 'mistral',
                'api_key' => env('MISTRAL_API_KEY'),
                'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
                'model' => env('MISTRAL_EMBEDDER_MODEL', 'mistral-embed'),
                // Mistral embed = 1024 dimensões
            ],
            'aws' => [
                'driver' => 'aws',
                'region' => env('AWS_REGION', 'us-east-1'),
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'model' => env('AWS_EMBEDDER_MODEL', 'amazon.titan-embed-text-v2:0'),
                // amazon.titan-embed-text-v2:0 = 1536 dimensões
                // amazon.titan-embed-text-v1 = 1024 dimensões
            ],
        ],

        // Opções: pgvector (PostgreSQL + pgvector), qdrant, database (qualquer banco do Laravel)
        'store' => env('AGENT_KNOWLEDGE_STORE', 'pgvector'),

        'stores' => [
            'pgvector' => [
                'driver' => 'pgvector',
                'connection' => env('AGENT_KNOWLEDGE_DB', 'pgsql'),
                'table' => 'knowledge_chunks',
            ],
            'qdrant' => [
                'driver' => 'qdrant',
                'url' => env('QDRANT_URL', 'http://localhost:6333'),
                'api_key' => env('QDRANT_API_KEY'),
                'collection' => env('QDRANT_COLLECTION', 'knowledge_chunks'),
                'timeout' => (float) env('QDRANT_TIMEOUT', 30),
                'batch_size' => (int) env('QDRANT_BATCH_SIZE', 100),
            ],
            'database' => [
                'driver' => 'database',
                // null = conexão padrão da aplicação (MySQL, MariaDB, PostgreSQL ou SQLite).
                // Os embeddings ficam numa tabela comum e são ranqueados em PHP: indicado para
                // bases de até alguns milhares de chunks por tenant e coleção.
                'connection' => env('AGENT_KNOWLEDGE_DB'),
                'table' => 'knowledge_chunks',
            ],
        ],

        // Threshold mínimo de similaridade pra retornar resultado
        // Cosseno: 0.0 (oposto) a 1.0 (idêntico)
        'min_relevance' => 0.65,

        // Top-K resultados retornados por busca
        'default_limit' => 5,

        // Coleções pré-configuradas (usadas com ->knowledgeBase('faq'))
        'collections' => [
            // Exemplo:
            // 'faq' => [
            //     'description' => 'Perguntas frequentes da clínica.',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Refactoring Agent
    |--------------------------------------------------------------------------
    | Deterministic signals used by the refactoring audit. They are candidates
    | for review, not automatic architectural verdicts.
    */
    'refactoring' => [
        'exclude' => [
            'vendor',
            'storage',
            'bootstrap/cache',
            'node_modules',
            '.git',
        ],
        'thresholds' => [
            'large_class_lines' => 500,
            'many_methods' => 20,
            'many_dependencies' => 12,
            'high_branching' => 25,
        ],
        'impact_thresholds' => [
            'low_max' => 2,
            'medium_max' => 7,
            'high_max' => 15,
        ],
        'facades' => [
            'Illuminate\\Support\\Facades\\',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Recovery
    |--------------------------------------------------------------------------
    | Retry automático, fallback entre providers e alertas via Discord.
    */
    'error_recovery' => [
        'enabled' => env('AGENT_ERROR_RECOVERY_ENABLED', true),

        'retry' => [
            'enabled' => true,

            'policies' => [
                'NETWORK_TIMEOUT' => [
                    'max_attempts' => 7,
                    'initial_delay_ms' => 1000,
                    'max_delay_ms' => 30000,
                    'multiplier' => 2.0,
                    'jitter' => true,
                ],
                'RATE_LIMIT' => [
                    'max_attempts' => 5,
                    'initial_delay_ms' => 2000,
                    'max_delay_ms' => 60000,
                    'multiplier' => 3.0,
                    'jitter' => true,
                ],
                'SERVER_ERROR' => [
                    'max_attempts' => 5,
                    'initial_delay_ms' => 1000,
                    'max_delay_ms' => 20000,
                    'multiplier' => 2.0,
                    'jitter' => true,
                ],
                'AUTH_ERROR' => [
                    'max_attempts' => 0,
                ],
                'INVALID_REQUEST' => [
                    'max_attempts' => 0,
                ],
            ],
        ],

        'fallback' => [
            'enabled' => true,

            'on_errors' => [
                'NETWORK_TIMEOUT',
                'RATE_LIMIT',
                'SERVER_ERROR',
                'AUTH_ERROR',
            ],

            'skip_on_errors' => [
                'INVALID_REQUEST',
            ],
        ],

        'alerts' => [
            'enabled' => env('AGENT_DISCORD_ALERTS_ENABLED', false),

            'discord' => [
                'webhook_url' => env('AGENT_DISCORD_WEBHOOK_URL'),
                'mention_on_critical' => env('AGENT_DISCORD_MENTION'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('AGENT_LOGGING', true),
        'channel' => env('AGENT_LOG_CHANNEL', 'stack'),
        'log_tool_calls' => env('AGENT_LOG_TOOL_CALLS', true),
        'log_messages' => env('AGENT_LOG_MESSAGES', false), // cuidado com PII
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics & Monitoring
    |--------------------------------------------------------------------------
    | Eventos de observabilidade (uso de tokens, tool calls, latência, retries).
    | 'enabled' controla o dispatch dos eventos. 'persist' controla se o listener
    | interno grava cada evento na tabela 'agent_kit_metrics'.
    */
    'analytics' => [
        'enabled' => env('AGENT_KIT_ANALYTICS_ENABLED', true),
        'persist' => env('AGENT_KIT_ANALYTICS_PERSIST', false),
        'table' => 'agent_kit_metrics',
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Server (Refactoring Agent)
    |--------------------------------------------------------------------------
    | Expõe as capabilities de refactoring a clientes MCP (Claude Code, Cursor,
    | Codex, MCP Inspector). stdio é o transporte padrão. HTTP é opt-in, faz
    | bind apenas em loopback por padrão e sempre exige bearer token.
    */
    'mcp' => [
        'enabled' => env('AGENT_KIT_MCP_ENABLED', true),
        'transport' => env('AGENT_KIT_MCP_TRANSPORT', 'stdio'),
        // null = base_path() da aplicação Laravel que hospeda o pacote
        'project_root' => env('AGENT_KIT_MCP_PROJECT_ROOT'),

        'http' => [
            'enabled' => env('AGENT_KIT_MCP_HTTP_ENABLED', false),
            'host' => env('AGENT_KIT_MCP_HTTP_HOST', '127.0.0.1'),
            'port' => (int) env('AGENT_KIT_MCP_HTTP_PORT', 8787),
            'path' => env('AGENT_KIT_MCP_HTTP_PATH', '/mcp'),
            // Bind fora de loopback exige opt-in explícito; o token continua obrigatório
            'allow_remote' => (bool) env('AGENT_KIT_MCP_ALLOW_REMOTE', false),
            // Hosts/origins adicionais permitidos (separados por vírgula); loopback já é permitido
            'allowed_origins' => env('AGENT_KIT_MCP_ALLOWED_ORIGINS', ''),
            // Mínimo de 32 caracteres. Gere com: php -r 'echo bin2hex(random_bytes(32));'
            'bearer_token' => env('AGENT_KIT_MCP_BEARER_TOKEN'),
            'max_body_bytes' => (int) env('AGENT_KIT_MCP_HTTP_MAX_BODY_BYTES', 1048576),
            'idle_timeout' => (int) env('AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT', 60),
            'max_concurrent_requests' => (int) env('AGENT_KIT_MCP_HTTP_MAX_CONCURRENT', 4),
            'session_ttl' => (int) env('AGENT_KIT_MCP_HTTP_SESSION_TTL', 3600),
            'max_sessions' => (int) env('AGENT_KIT_MCP_HTTP_MAX_SESSIONS', 100),
        ],

        'index_cache' => [
            // Índices AST mantidos em memória por processo (um por raiz de projeto)
            'max_entries' => (int) env('AGENT_KIT_MCP_INDEX_CACHE_MAX_ENTRIES', 1),
        ],

        'logging' => [
            'level' => env('AGENT_KIT_MCP_LOG_LEVEL', 'info'),
            // null = stderr (obrigatório para stdio); ou um canal de config/logging.php
            'channel' => env('AGENT_KIT_MCP_LOG_CHANNEL'),
        ],
    ],
];
