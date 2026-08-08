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
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('AGENT_LOGGING', true),
        'channel' => env('AGENT_LOG_CHANNEL', 'stack'),
        'log_tool_calls' => true,
        'log_messages' => false, // cuidado com PII
    ],
];
