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
