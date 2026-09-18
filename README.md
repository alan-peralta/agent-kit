# Agent Kit

Toolkit Laravel para construir agentes de IA com suporte a múltiplos providers (OpenAI, Anthropic, Gemini, DeepSeek), tools customizadas e RAG via pgvector, Qdrant ou o próprio banco relacional (MySQL, MariaDB, PostgreSQL ou SQLite).

## Filosofia

- **Pacote = infraestrutura.** Loop de execução, providers, persistência, RAG genérico.
- **Projeto = domínio.** Tools concretas (queries, ações, regras de negócio).

## Instalação

> O pacote ainda não está publicado no Packagist. Registre o repositório Git
> antes de instalar.

```bash
composer config repositories.agent-kit vcs https://github.com/alan-peralta/agent-kit
composer require peralta/agent-kit:^0.3
php artisan vendor:publish --tag=agent-kit-config
php artisan vendor:publish --tag=agent-kit-migrations
php artisan migrate
```

Isso basta para o núcleo de agentes: providers, tools, conversas e RAG. O Refactoring Agent
e o servidor MCP usam pacotes opcionais, instalados à parte e de preferência só em
desenvolvimento; veja [Refactoring Agent](#refactoring-agent) e [Servidor MCP](#servidor-mcp).

A tag `agent-kit-migrations` publica só as tabelas de conversas (`agent_messages`) e de
métricas (`agent_kit_metrics`). Elas usam apenas tipos portáveis e são testadas no CI em
MySQL 8.4, MariaDB 11.8, PostgreSQL 17 e SQLite.

A tabela da knowledge base tem uma tag por store. Publique só a do store que você usa,
antes do `migrate`:

| Store (`AGENT_KNOWLEDGE_STORE`) | Tag | Banco |
|---|---|---|
| `pgvector` (padrão) | `agent-kit-pgvector-migrations` | PostgreSQL com a extensão pgvector |
| `database` | `agent-kit-database-store-migrations` | MySQL, MariaDB, PostgreSQL ou SQLite |
| `qdrant` | nenhuma | a coleção é criada no Qdrant na primeira escrita |

```bash
# exemplo com pgvector
php artisan vendor:publish --tag=agent-kit-pgvector-migrations
php artisan migrate
```

A migration do pgvector executa `CREATE EXTENSION IF NOT EXISTS vector` na conexão
`pgsql` (ou na de `AGENT_KNOWLEDGE_DB`), então esse banco precisa estar configurado em
`config/database.php` antes do `migrate`. Quem não usa RAG não publica nenhuma tag de
knowledge base.

As migrations de knowledge base dos stores `pgvector` e `database` criam a mesma tabela,
`knowledge_chunks`. Publique só a tag do store em uso e evite `vendor:publish --provider`,
que publica todas as tags. Para trocar de store, apague a tabela antiga ou mude `table` no
config.

Alternativa mais curta para um checkout local do pacote:

```bash
composer config repositories.agent-kit path ../agent-kit
composer require peralta/agent-kit:@dev
```

## Atualizando

Vindo da v0.3.x ou anterior: a migration do `knowledge_chunks` para pgvector saiu da tag
`agent-kit-migrations` e passou para `agent-kit-pgvector-migrations`.

- Quem usa pgvector e já publicou as migrations não precisa fazer nada, porque o arquivo
  mantém o mesmo nome. Instalações novas com pgvector publicam as duas tags.
- Quem usa MySQL, MariaDB, SQLite ou Qdrant e já publicou as migrations de uma versão
  anterior deve apagar `database/migrations/2026_05_05_000002_create_knowledge_chunks_table.php`
  do app, se ela ainda não rodou: ela exige PostgreSQL com pgvector e faz o `migrate` falhar.
- O store `database` funciona com um `config/agent-kit.php` publicado antes desta versão,
  usando a conexão padrão e a tabela `knowledge_chunks`. Para usar `AGENT_KNOWLEDGE_DB` ou
  outra tabela, copie o bloco `database` de `knowledge.stores` do config do pacote para o seu.

Vindo da v0.3.x ou anterior: `mcp/sdk` e `nikic/php-parser` deixaram de ser dependências
obrigatórias. Com eles saem da sua aplicação o plugin do Composer `php-http/discovery`, as
dependências do SDK e a exigência de `ext-fileinfo`. Quem usa só o núcleo de agentes não
precisa fazer nada. Quem usa o servidor MCP (`agent-kit:mcp`) ou os comandos de AST do
Refactoring Agent (`refactor-analyze`, `-callers`, `-dependencies` e `-impact`) instala os
dois depois de atualizar:

```bash
composer require --dev mcp/sdk nikic/php-parser
```

Sem eles, o `agent-kit:mcp` sai com `The MCP server requires mcp/sdk` e os comandos de AST
respondem com o código de erro `DEPENDENCY_MISSING`, sempre com o comando de instalação.
O SDK traz o plugin do Composer `php-http/discovery`, que o kit não usa; veja em
[MCP_SERVER.md](MCP_SERVER.md#prerequisites) como recusá-lo antes do `require`.

Vindo da v0.3.x ou anterior: o Refactoring Agent passou a ignorar `.claude` e `.worktrees`,
onde agentes de código guardam worktrees completas do projeto. Se você publicou o
`config/agent-kit.php`, acrescente as duas pastas a `refactoring.exclude`: a lista publicada
substitui a do pacote.

Vindo da v0.3.x ou anterior: quem usava o transporte HTTP do servidor MCP
(`agent-kit:mcp --transport=http`) precisa migrar para a rota da própria aplicação.
Habilite `AGENT_KIT_MCP_HTTP_ENABLED=true` e um `AGENT_KIT_MCP_BEARER_TOKEN` no `.env`,
sirva a aplicação como sempre (`php artisan serve`, PHP-FPM ou Octane) e aponte o cliente
para `<APP_URL><AGENT_KIT_MCP_HTTP_PATH>` (por exemplo, `http://127.0.0.1:8000/mcp` com
`php artisan serve`) em vez do antigo `--host`/`--port`. Remova do `.env` as variáveis
`AGENT_KIT_MCP_HTTP_HOST`, `AGENT_KIT_MCP_HTTP_PORT`, `AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT`,
`AGENT_KIT_MCP_HTTP_MAX_CONCURRENT` e `AGENT_KIT_MCP_HTTP_MAX_SESSIONS`, que não existem
mais; `react/http` deixou de ser necessário. `agent-kit:mcp --transport=http` agora sai
com uma mensagem explicando a mudança em vez de tentar escutar. Duas variáveis mudam de
sentido: `AGENT_KIT_MCP_ALLOW_REMOTE=true` agora faz a rota aceitar clientes com IP fora
de loopback (antes permitia fazer o bind do processo num endereço fora de loopback), e
`AGENT_KIT_MCP_HTTP_ENABLED=true` registra a rota em todo ambiente que lê esse `.env`, não
só no processo que você iniciava à mão; habilite-a apenas no `.env` de desenvolvimento. Veja
[MCP_SERVER.md](MCP_SERVER.md#streamable-http).

Vindo da v0.2.0: a v0.3.0 é retrocompatível — só adiciona as opções por chamada
`response_format` e `timeout`. Como `^0.2` não alcança a 0.3.0, ajuste a restrição
(`composer require peralta/agent-kit:^0.3`). Nenhuma config nova para republicar e
nenhuma migration nova.

Vindo da v0.1.0: `composer update peralta/agent-kit`, depois
`php artisan vendor:publish --tag=agent-kit-config --force` para trazer as novas
seções `refactoring` e `mcp` (ou deixe o merge automático de config resolver, se você
não usa `config:cache`). Se usa cache de config, rode `php artisan config:clear`.
Nenhuma migration nova é necessária. Veja [CHANGELOG.md](CHANGELOG.md).

## Configuração

Defina no `.env` os providers que vai usar:

```
AGENT_PROVIDER=openai

OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
DEEPSEEK_API_KEY=...

AGENT_CONVERSATION_DRIVER=database
AGENT_KNOWLEDGE_DB=pgsql
```

Veja [`.env.example`](.env.example) para a lista completa de variáveis, incluindo
conversation stores, embedders, Qdrant, [Error Recovery](#error-recovery) e
[Analytics](#analytics--monitoring).

## Uso básico

```php
use Peralta\AgentKit\Facades\Agent;

$response = Agent::make()
    ->provider('anthropic')
    ->system('Você é assistente da clínica X.')
    ->send('Qual o horário de atendimento?');

echo $response->text();
```

### Opções por chamada

`options()` repassa chaves ao provider da chamada. Nos providers compatíveis com a API
da OpenAI (`openai` e `deepseek`), duas delas ajustam a requisição:

```php
$response = Agent::make()
    ->provider('deepseek')
    ->system('Responda apenas com JSON.')
    ->options([
        'response_format' => ['type' => 'json_object'],
        'timeout' => 8,
    ])
    ->send('Liste 3 frutas no formato {"frutas": [...]}.');
```

- `response_format` — array repassado como recebido no corpo de `POST chat/completions`.
  Use `['type' => 'json_object']` para que o provider garanta um JSON parseável em vez de
  depender do prompt. Sem a opção, a chave não é enviada.
- `timeout` — timeout da requisição HTTP em segundos (`int|float`). Sobrescreve
  `agent-kit.providers.*.timeout` (default 60 s) apenas naquela chamada e nunca entra no
  payload JSON. Sem a opção, vale o timeout de client.

Ambas valem por chamada; `AnthropicProvider` e `GeminiProvider` não as consomem.

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

### Banco relacional (MySQL, MariaDB, PostgreSQL, SQLite)

O store `database` guarda os embeddings numa tabela comum e calcula a similaridade de
cosseno em PHP. Ele funciona em qualquer banco suportado pelo Laravel, inclusive MySQL 8
Community, que não tem busca vetorial nativa.

```env
AGENT_KNOWLEDGE_STORE=database
# vazio = conexão padrão da aplicação
AGENT_KNOWLEDGE_DB=
```

```bash
php artisan vendor:publish --tag=agent-kit-database-store-migrations
php artisan migrate
```

Com um `config/agent-kit.php` publicado antes desta versão, o store usa a conexão padrão e
a tabela `knowledge_chunks`; veja [Atualizando](#atualizando).

Cada busca lê todos os embeddings do tenant, e da coleção quando ela é informada, então o
custo cresce de forma linear. Medido em MySQL 8.4 (Docker num Apple M4 Pro) com embeddings
de 1536 dimensões e a tabela já no buffer pool:

| Chunks por tenant e coleção | Tempo por busca |
|---|---|
| 1.000 | 120 ms |
| 5.000 | 460 ms |
| 10.000 | 1,0 s |

Quase todo o tempo é leitura: cada chunk carrega cerca de 8 KB de embedding. Com o cache
frio, ou com um `innodb_buffer_pool_size` menor que a tabela, as mesmas buscas levaram de
duas a três vezes mais.

Use para FAQs, políticas e manuais de até alguns milhares de chunks por tenant e coleção.
Acima disso, prefira pgvector ou Qdrant. Trocar de embedder exige reindexar: uma busca com
dimensão diferente da armazenada lança `KnowledgeStoreException`.

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

## Refactoring Agent

O Agent Kit inclui um auditor inicial de refatoração para PHP/Laravel. Ele coleta sinais determinísticos do codebase para que agentes de coding possam raciocinar com dados objetivos antes de propor mudanças.

```bash
php artisan agent-kit:refactor-capabilities --json
php artisan agent-kit:refactor-audit
php artisan agent-kit:refactor-audit /path/to/project
php artisan agent-kit:refactor-analyze app/Services/PaymentService.php
php artisan agent-kit:refactor-callers "App\Services\PaymentService::charge"
php artisan agent-kit:refactor-dependencies "App\Services\PaymentService"
php artisan agent-kit:refactor-impact "App\Services\PaymentService::charge" --json --path=/project
```

Em `agent-kit:refactor-audit`, a raiz opcional é um argumento posicional, como
em `php artisan agent-kit:refactor-audit /path/to/project`. Analyze, callers,
dependencies e impact usam `--path=/project` para outra raiz. Use `--json` para
obter saída estruturada adequada a agentes e automações. Todos os comandos são
somente de análise: nenhum deles modifica o código examinado.

`refactor-capabilities` e `refactor-audit` funcionam com a instalação padrão. Analyze,
callers, dependencies e impact montam o índice AST e precisam do `nikic/php-parser` 5.x,
que o pacote só sugere. Em desenvolvimento ele costuma já estar presente por causa do
PHPUnit; se não estiver:

```bash
composer require --dev nikic/php-parser
```

Sem ele, esses comandos respondem com o código de erro `DEPENDENCY_MISSING` e o comando de
instalação.

Os relatórios de auditoria são gravados em `.agent-kit/refactoring/`. Veja
[REFACTORING_AGENT.md](REFACTORING_AGENT.md) para arquitetura, tipos de
dependência, níveis de confiança, workflow e limitações.

## Using Refactoring Agent with Coding Agents

Instale as skills nativas no projeto que será analisado:

```bash
php artisan agent-kit:agents:install cursor --path=/project
php artisan agent-kit:agents:install claude --path=/project
php artisan agent-kit:agents:install --all --path=/project
```

As skills geradas usam duas fontes: as tools MCP e, como fallback, os comandos
`php artisan agent-kit:refactor-* --json` executados **dentro** de `/project`.
Esse fallback só funciona se `/project` também tiver o `peralta/agent-kit`
instalado (veja [Instalação](#instalação)). Se não tiver, mantenha o servidor MCP
rodando com `--path=/project` — ele é a única fonte de dados nesse caso.

`--path=/project` precisa apontar para um diretório existente; um valor vazio é
rejeitado. Use agentes posicionais (`cursor`, `claude`) ou `--all`, nunca ambos.
Arquivos personalizados em conflito são preservados, exceto quando `--force` é
fornecido explicitamente.

A instalação grava, por agente:

```text
.claude/skills/refactor-{audit,analyze,callers,dependencies,impact,plan}/SKILL.md
.claude/rules/agent-kit-refactoring.md
.cursor/skills/refactor-{audit,analyze,callers,dependencies,impact,plan}/SKILL.md
.cursor/rules/agent-kit-refactoring.mdc
```

Comite esses arquivos se toda a equipe deve compartilhar o mesmo workflow.

Cursor e Claude Code recebem os mesmos seis comandos portáveis:

```text
/refactor-audit
/refactor-analyze <target>
/refactor-callers <target>
/refactor-dependencies <target>
/refactor-impact <target>
/refactor-plan <target>
```

Exemplo: `/refactor-impact App\Services\PaymentService::charge`.

As skills tentam obter fatos na ordem: tools MCP do Agent Kit
(`refactoring_capabilities`, `refactoring_audit`, `refactoring_analyze`,
`refactoring_callers`, `refactoring_dependencies`, `refactoring_impact`),
CLI `agent-kit:refactor-* --json`, leitura/pesquisa no
repositório e, por último, interpretação do LLM. Elas separam `FACTS`,
`INTERPRETATION` e `RECOMMENDATIONS`, sinalizam comportamento dinâmico não
resolvido e mantêm `ANALYZE != MODIFY`.

O Refactoring Core é compartilhado pela CLI, pelos coding agents e pelo servidor
MCP. No `/refactor-plan`, a saída é somente um plano. No `/refactor-audit` e nos
demais comandos, a saída é somente análise. Nenhum comando aplica mudanças
automaticamente. Nenhum comando `/refactor-apply` é gerado e a tool
`refactoring_apply` não existe.

```text
               Refactoring Core
                     |
      +--------------+--------------+
      v              v              v
     CLI        Coding Agents       MCP
```

## Servidor MCP

O pacote inclui um servidor MCP (`mcp/sdk` oficial) que expõe as seis tools
somente-leitura acima a Claude Code, Cursor, Codex, MCP Inspector e clientes
Streamable HTTP:

```bash
# stdio (padrão) — use em .mcp.json / .cursor/mcp.json / ~/.codex/config.toml
php artisan agent-kit:mcp --path=/caminho/absoluto/do/projeto
```

Streamable HTTP não é um processo à parte: é uma rota da própria aplicação Laravel,
opt-in, loopback por padrão e sempre com bearer token.

```env
# .env
AGENT_KIT_MCP_HTTP_ENABLED=true
AGENT_KIT_MCP_BEARER_TOKEN=... # 32+ caracteres
```

```bash
php artisan serve
# endpoint: <APP_URL><AGENT_KIT_MCP_HTTP_PATH>, ex.: http://127.0.0.1:8000/mcp
```

O servidor usa pacotes que a instalação padrão não traz. Instale-os em desenvolvimento
antes do primeiro uso:

```bash
composer require --dev mcp/sdk nikic/php-parser
```

Configuração em `config/agent-kit.php` (`mcp`) e variáveis `AGENT_KIT_MCP_*`
no `.env.example`. Veja [MCP_SERVER.md](MCP_SERVER.md) para transporte,
autenticação, origins permitidas, cache do índice, exemplos por cliente,
diagnóstico e limitações.

## Documentação

- [SETUP.md](SETUP.md) — guia completo de configuração (PostgreSQL + pgvector ou MySQL/MariaDB, Redis, RAG)
- [ARCHITECTURE.md](ARCHITECTURE.md) — Redis vs Database vs Knowledge Base, e o Refactoring Core
- [REFACTORING_AGENT.md](REFACTORING_AGENT.md) — Refactoring Agent: comandos, análise AST, workflow
- [MCP_SERVER.md](MCP_SERVER.md) — servidor MCP: transportes, segurança, clientes
- [EMBEDDERS.md](EMBEDDERS.md) e [HYBRID_STORAGE.md](HYBRID_STORAGE.md) — embedders e storage híbrido
- [CHANGELOG.md](CHANGELOG.md) — histórico de versões

## Contribuindo

Veja [CONTRIBUTING.md](CONTRIBUTING.md).

## Licença

MIT. Veja [LICENSE](LICENSE).
