# Agent Kit

Toolkit Laravel para construir agentes de IA com suporte a múltiplos providers (OpenAI, Anthropic, Gemini, DeepSeek), tools customizadas e RAG via pgvector.

## Filosofia

- **Pacote = infraestrutura.** Loop de execução, providers, persistência, RAG genérico.
- **Projeto = domínio.** Tools concretas (queries, ações, regras de negócio).

## Instalação

```bash
composer require peralta/agent-kit
php artisan vendor:publish --tag=agent-kit-config
php artisan vendor:publish --tag=agent-kit-migrations
php artisan migrate
```

## Configuração

Defina no `.env` os providers que vai usar:

```
AGENT_PROVIDER=openai

OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
DEEPSEEK_API_KEY=...

AGENT_CONVERSATION_DRIVER=database
AGENT_KNOWLEDGE_DB=pgsql_knowledge
```

## Uso básico

```php
use Peralta\AgentKit\Facades\Agent;

$response = Agent::make()
    ->provider('anthropic')
    ->system('Você é assistente da clínica X.')
    ->send('Qual o horário de atendimento?');

echo $response->text();
```

## Criando uma tool

```php
namespace App\AI\Tools;

use Peralta\AgentKit\Tools\AbstractTool;
use Peralta\AgentKit\DTOs\Context;
use App\Models\Agendamento;

class BuscarAgendamentos extends AbstractTool
{
    public function name(): string
    {
        return 'buscar_agendamentos';
    }

    public function description(): string
    {
        return 'Busca agendamentos da clínica por médico e período. '
             . 'Retorna lista com paciente, horário e status.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'medico_id' => ['type' => 'integer'],
                'data_inicio' => ['type' => 'string', 'format' => 'date'],
                'data_fim' => ['type' => 'string', 'format' => 'date'],
            ],
        ];
    }

    public function authorize(Context $context): bool
    {
        return $context->user?->can('ver_agendamentos') ?? false;
    }

    public function handle(array $input, Context $context): mixed
    {
        return Agendamento::query()
            ->where('clinic_id', $context->tenantId)
            ->when($input['medico_id'] ?? null, fn($q, $id) => $q->where('medico_id', $id))
            ->when($input['data_inicio'] ?? null, fn($q, $d) => $q->where('data_hora', '>=', $d))
            ->when($input['data_fim'] ?? null, fn($q, $d) => $q->where('data_hora', '<=', $d))
            ->limit(50)
            ->get()
            ->toArray();
    }
}
```

Uso:

```php
$response = Agent::make()
    ->tools([BuscarAgendamentos::class])
    ->context([
        'tenant_id' => auth()->user()->clinic_id,
        'user' => auth()->user(),
    ])
    ->send('Quais consultas tenho marcadas pra semana que vem?');
```

## RAG (Knowledge Base)

Configure coleções em `config/agent-kit.php`:

```php
'collections' => [
    'faq' => [
        'description' => 'Perguntas frequentes da clínica sobre convênios, '
                       . 'horários, valores e políticas de atendimento.',
    ],
],
```

Indexe documentos:

```php
use Peralta\AgentKit\Knowledge\KnowledgeIndexer;

$indexer = app(KnowledgeIndexer::class);

$indexer->indexText(
    tenantId: '1',
    collection: 'faq',
    source: 'faq_v3.pdf',
    text: $textoExtraido,
    metadata: ['versao' => '2025-03'],
);
```

Use no agent:

```php
$response = Agent::make()
    ->knowledgeBase('faq')
    ->context(['tenant_id' => '1'])
    ->send('Vocês atendem Unimed?');
```

### Qdrant

Use Qdrant Cloud ou uma instância self-hosted sem alterar as APIs de indexação ou agente:

```env
AGENT_KNOWLEDGE_STORE=qdrant
QDRANT_URL=https://your-cluster.cloud.qdrant.io:6333
QDRANT_API_KEY=your-api-key
QDRANT_COLLECTION=knowledge_chunks
```

Para uma instância local sem segurança, deixe `QDRANT_API_KEY` vazio. O pacote cria a coleção física na primeira escrita usando a dimensão do embedding e distância cosseno. Uma coleção física é compartilhada; isolamento de tenant e coleção lógica são aplicados através de filtros de payload.

## Conversas com persistência

```php
$response = Agent::make()
    ->conversation('user_123_session_abc')
    ->tools([BuscarAgendamentos::class])
    ->context(['tenant_id' => '1'])
    ->send('E quais foram os de ontem?'); // entende contexto da última conversa
```

## Combinando tudo

```php
$response = Agent::make()
    ->provider('anthropic')
    ->system($systemPrompt)
    ->conversation("user_{$user->id}")
    ->tools([
        BuscarAgendamentos::class,
        CriarAgendamento::class,
        BalancoFinanceiro::class,
    ])
    ->knowledgeBase('faq')
    ->knowledgeBase('manual_interno')
    ->context([
        'tenant_id' => $user->clinic_id,
        'user' => $user,
    ])
    ->send($mensagem);
```

## Trocando de provider

Em runtime: `->provider('openai')` ou `->provider('deepseek')`.
Default: `AGENT_PROVIDER=...` no `.env`.

Toda a lógica de tools e RAG é agnóstica. O loop adapta automaticamente
o formato de mensagens e tool calls pra cada provider.

## Error Recovery

O Agent Kit tenta recuperar automaticamente de falhas transitórias nos providers:

1. **Retry** com backoff exponencial + jitter (configurável por tipo de erro)
2. **Fallback** para outro provider configurado se o atual esgotar as tentativas
3. **Alerta no Discord** quando um fallback é ativado ou quando todos os providers falham

Configure em `.env`:

```env
AGENT_ERROR_RECOVERY_ENABLED=true
AGENT_DISCORD_ALERTS_ENABLED=true
AGENT_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/SEU_ID/SEU_TOKEN
AGENT_DISCORD_MENTION=@oncall
```

Políticas de retry por tipo de erro (timeouts, rate limit, erros de servidor, auth,
requisições inválidas) ficam em `config/agent-kit.php` → `error_recovery`. Erros de
`INVALID_REQUEST` (400) nunca são retentados nem geram fallback, pois indicam erro
da própria aplicação.

Todo o processo é transparente: o usuário final recebe a resposta normalmente,
sem saber que houve retry ou troca de provider.

## Analytics & Monitoring

O Agent Kit dispara eventos durante o ciclo de vida de cada requisição, permitindo
observabilidade sem acoplar o core a nenhuma ferramenta específica de métricas.

Configure em `config/agent-kit.php` → `analytics`:

```php
'analytics' => [
    'enabled' => env('AGENT_KIT_ANALYTICS_ENABLED', true),
    'persist' => env('AGENT_KIT_ANALYTICS_PERSIST', false),
    'table' => 'agent_kit_metrics',
],
```

- `analytics.enabled` — liga/desliga o disparo dos eventos de analytics.
- `analytics.persist` — quando `true`, persiste as métricas na tabela configurada
  (via listener dedicado), além de logar.
- `analytics.table` — nome da tabela usada para persistir as métricas.

Eventos disparados:

- `AgentRequestStarted` — disparado no início de `Agent::send()`
- `AgentRequestCompleted` — disparado quando a resposta não tem mais tool calls
- `ProviderCallCompleted` — disparado após cada chamada ao provider dentro do loop de `send()`
- `TokenUsageRecorded` — disparado com as contagens de tokens de entrada/saída
- `ToolCallExecuted` — disparado após cada execução de tool
- `RecoveryAttempted` — disparado a cada tentativa de retry/fallback
- `RecoveryExhausted` — disparado quando todas as tentativas de recuperação se esgotam
