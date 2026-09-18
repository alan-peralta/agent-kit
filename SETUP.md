# 🚀 Guia Completo de Configuração - Agent Kit + RAG

Configuração passo a passo para usar Agent Kit com PostgreSQL + Redis + RAG (Knowledge Base). Para MySQL ou MariaDB, siga as notas "MySQL/MariaDB" dos Passos 1 e 3.

## ✅ Pré-requisitos

- Laravel 10+ (suporta 10, 11, 12 e 13; o 13 exige PHP 8.3)
- PHP 8.2+
- PostgreSQL com extensão pgvector, ou MySQL/MariaDB com o store `database` ou Qdrant
- Redis
- Uma API de LLM (OpenAI, Anthropic, Gemini ou DeepSeek)

---

## 📋 Passo 1: Instalar o pacote

> O pacote ainda não está publicado no Packagist. Registre o repositório Git
> antes de instalar.

```bash
composer config repositories.agent-kit vcs https://github.com/alan-peralta/agent-kit
composer require peralta/agent-kit:^0.4
php artisan vendor:publish --tag=agent-kit-config
php artisan vendor:publish --tag=agent-kit-migrations
php artisan vendor:publish --tag=agent-kit-pgvector-migrations
```

> MySQL/MariaDB: troque a tag do pgvector por `agent-kit-database-store-migrations` para
> usar o store `database`, ou não publique nenhuma tag de knowledge base se for usar Qdrant.

> Refactoring Agent e servidor MCP: os comandos de AST (`refactor-analyze`, `-callers`,
> `-dependencies` e `-impact`) e o `agent-kit:mcp` usam pacotes opcionais. Instale-os só em
> desenvolvimento com `composer require --dev mcp/sdk nikic/php-parser`; veja
> [MCP_SERVER.md](MCP_SERVER.md).

Alternativa mais curta para um checkout local do pacote:

```bash
composer config repositories.agent-kit path ../agent-kit
composer require peralta/agent-kit:@dev
```

---

## 🔧 Passo 2: Configurar `.env`

Adicione as seguintes variáveis de ambiente:

```env
# LLM Provider (escolha um)
AGENT_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-xxxxx
OPENAI_API_KEY=sk-xxxxx              # necessário para embeddings do RAG

# Conversas persistentes (Redis)
AGENT_CONVERSATION_DRIVER=redis
AGENT_CONVERSATION_REDIS=default

# Knowledge Base: pgvector (PostgreSQL), database (MySQL, MariaDB, PostgreSQL ou SQLite) ou qdrant
AGENT_KNOWLEDGE_STORE=pgvector
AGENT_KNOWLEDGE_DB=pgsql
AGENT_EMBEDDER=openai

# Logging (opcional)
AGENT_LOGGING=true
AGENT_LOG_CHANNEL=stack

# Error Recovery (opcional - retry, fallback entre providers e alertas)
AGENT_ERROR_RECOVERY_ENABLED=true
AGENT_DISCORD_ALERTS_ENABLED=false
AGENT_DISCORD_WEBHOOK_URL=
AGENT_DISCORD_MENTION=

# Analytics & Monitoring (opcional)
AGENT_KIT_ANALYTICS_ENABLED=true
AGENT_KIT_ANALYTICS_PERSIST=false

# Servidor MCP (opcional - Claude Code, Cursor, Codex, Inspector)
AGENT_KIT_MCP_ENABLED=true
AGENT_KIT_MCP_HTTP_ENABLED=false
# AGENT_KIT_MCP_BEARER_TOKEN=   # obrigatório só com AGENT_KIT_MCP_HTTP_ENABLED=true (32+ chars)
```

> MySQL/MariaDB: use `AGENT_KNOWLEDGE_STORE=database` e deixe `AGENT_KNOWLEDGE_DB=` vazio
> para usar a conexão padrão da aplicação, ou `AGENT_KNOWLEDGE_STORE=qdrant` com as
> variáveis `QDRANT_*`.

### Opções de Provider:
- `openai` - GPT-4, GPT-4o
- `anthropic` - Claude
- `gemini` - Google Gemini
- `deepseek` - DeepSeek

---

## 🗄️ Passo 3: Rodar as migrations

> ⚠️ Com a tag `agent-kit-pgvector-migrations` publicada, este passo exige o PostgreSQL com pgvector **já rodando e configurado** como a
> conexão `pgsql` (ou a de `AGENT_KNOWLEDGE_DB`). Num app Laravel 11, 12 ou 13 novo, que vem
> com `DB_CONNECTION=sqlite`, o comando falha com
> `SQLSTATE[HY000]: General error: 1 near "EXTENSION": syntax error`.
> A verificação do Passo 4 acontece depois da migration — confirme o banco antes.

> MySQL/MariaDB: sem a tag do pgvector, o `migrate` cria as tabelas do pacote no banco
> padrão da aplicação, e a `knowledge_chunks` só se você publicou
> `agent-kit-database-store-migrations`.

```bash
php artisan migrate
```

Isso cria:
1. **Tabela `agent_messages`** - Histórico de mensagens
2. **Tabela `agent_kit_metrics`** - Métricas de uso (gravadas com `AGENT_KIT_ANALYTICS_PERSIST=true`)
3. **Tabela `knowledge_chunks`** - Documentos indexados para RAG (pgvector ou store `database`)
4. **Extensão `pgvector`** no PostgreSQL - Só com a tag do pgvector

---

## ✔️ Passo 4: Verificar pgvector (somente pgvector)

Confirme que pgvector foi instalado corretamente:

```bash
php artisan tinker

# Dentro do tinker:
DB::select('CREATE EXTENSION IF NOT EXISTS vector');
DB::select("SELECT 1 FROM pg_extension WHERE extname = 'vector'");
# Deve retornar: [stdClass Object ( [1] => 1 )]

exit
```

---

## 🛠️ Passo 5: Criar sua primeira Tool customizada

Crie `app/AI/Tools/BuscarProdutos.php`:

```php
<?php

namespace App\AI\Tools;

use Peralta\AgentKit\Tools\AbstractTool;
use Peralta\AgentKit\DTOs\Context;
use App\Models\Product;

class BuscarProdutos extends AbstractTool
{
    public function name(): string
    {
        return 'buscar_produtos';
    }

    public function description(): string
    {
        return 'Busca produtos no catálogo por nome, categoria ou preço. '
             . 'Retorna lista com ID, nome, preço e descrição.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'busca' => [
                    'type' => 'string',
                    'description' => 'Termo de busca (nome ou categoria)',
                ],
                'preco_maximo' => [
                    'type' => 'number',
                    'description' => 'Filtro de preço máximo (opcional)',
                ],
            ],
            'required' => ['busca'],
        ];
    }

    public function authorize(Context $context): bool
    {
        // Qualquer um pode buscar produtos
        return true;
    }

    public function handle(array $input, Context $context): mixed
    {
        $query = Product::query()
            ->where('name', 'ilike', "%{$input['busca']}%")
            ->orWhere('category', 'ilike', "%{$input['busca']}%");

        if (isset($input['preco_maximo'])) {
            $query->where('price', '<=', $input['preco_maximo']);
        }

        return $query
            ->limit(10)
            ->get(['id', 'name', 'price', 'description'])
            ->toArray();
    }
}
```

---

## 📚 Passo 6: Indexar documentos no RAG

Crie um comando `app/Console/Commands/IndexKnowledge.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Peralta\AgentKit\Knowledge\KnowledgeIndexer;

class IndexKnowledge extends Command
{
    protected $signature = 'knowledge:index';
    protected $description = 'Indexa FAQ e documentos no RAG';

    public function handle(KnowledgeIndexer $indexer)
    {
        // Exemplo 1: Indexar FAQ simples
        $faq = [
            [
                'pergunta' => 'Qual é o horário de funcionamento?',
                'resposta' => 'Segunda a sexta, 9h às 18h.',
            ],
            [
                'pergunta' => 'Vocês entregam em qual região?',
                'resposta' => 'Entregamos em todo Brasil via transportadora.',
            ],
            [
                'pergunta' => 'Qual é a política de devoluções?',
                'resposta' => '30 dias de garantia com devolução grátis.',
            ],
        ];

        foreach ($faq as $item) {
            $text = "{$item['pergunta']} {$item['resposta']}";
            
            $indexer->indexText(
                tenantId: '1',
                collection: 'faq',
                source: 'faq_v1.txt',
                text: $text,
                metadata: [
                    'tipo' => 'faq',
                    'indexado_em' => now()->toDateString(),
                ],
            );
        }

        // Exemplo 2: Indexar política de privacidade
        $politica = file_get_contents('storage/docs/politica-privacidade.txt');
        $indexer->indexText(
            tenantId: '1',
            collection: 'politica',
            source: 'politica-privacidade.txt',
            text: $politica,
            metadata: ['tipo' => 'legal'],
        );

        $this->info('✓ Conhecimento indexado com sucesso!');
    }
}
```

Execute o comando:

```bash
php artisan knowledge:index
```

---

## 💬 Passo 7: Usar o Agent com tudo integrado

Crie um controlador `app/Http/Controllers/ChatController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Peralta\AgentKit\Facades\Agent;
use App\AI\Tools\BuscarProdutos;

class ChatController extends Controller
{
    public function chat(Request $request)
    {
        $userId = auth()->id();
        $mensagem = $request->input('message');

        try {
            $response = Agent::make()
                ->provider('anthropic')
                ->model('claude-opus-4-5')
                ->system('Você é um assistente de vendas. Ajuda clientes a encontrar produtos, '
                       . 'responder dúvidas sobre políticas e fazer recomendações.')
                ->conversation("user_{$userId}")
                ->tools([BuscarProdutos::class])
                ->knowledgeBase('faq', 'Perguntas frequentes sobre compras')
                ->knowledgeBase('politica', 'Políticas de privacidade e devolução')
                ->context([
                    'user_id' => $userId,
                    'user' => auth()->user(),
                ])
                ->send($mensagem);

            return response()->json([
                'message' => $response->text(),
                'tokens' => [
                    'input' => $response->inputTokens,
                    'output' => $response->outputTokens,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
```

---

## 🔌 Passo 8: Configurar as rotas

Em `routes/api.php`:

```php
Route::middleware('auth:sanctum')->post('/chat', [ChatController::class, 'chat']);
```

---

## 🧪 Passo 9: Testar no Tinker

```bash
php artisan tinker

# Teste simples:
use App\AI\Tools\BuscarProdutos;
use Peralta\AgentKit\Facades\Agent;

$response = Agent::make()
    ->provider('anthropic')
    ->system('Responda em português.')
    ->tools([BuscarProdutos::class])
    ->knowledgeBase('faq')
    ->send('Qual é o seu horário de funcionamento?');

echo $response->text();

exit
```

---

## 🛡️ Passo 10: Error Recovery e Analytics (opcional)

O Agent Kit já vem com recuperação automática de falhas e observabilidade prontas,
bastando habilitar via `.env`:

- **Retry com backoff exponencial** por tipo de erro (timeout, rate limit, erro de
  servidor) e **fallback automático** para outro provider configurado.
- **Alerta no Discord** quando um fallback é ativado ou quando todos os providers falham.
- **Eventos de analytics** (uso de tokens, tool calls, latência, retries), com
  persistência opcional na tabela `agent_kit_metrics`.

A tabela `agent_kit_metrics` já foi criada no Passo 3: ela vem na tag
`agent-kit-migrations`, junto com `agent_messages`. Para persistir métricas, basta
ligar `AGENT_KIT_ANALYTICS_PERSIST=true` no `.env`.

Ajuste as políticas de retry por tipo de erro e as opções de analytics em
`config/agent-kit.php` → `error_recovery` e `analytics`. Detalhes completos em
[README.md](README.md#error-recovery) e [README.md](README.md#analytics--monitoring).

---

## 📊 Fluxo Completo

```
Usuário envia: "Quero um produto barato e também saber as políticas"
                    ↓
         Agent recebe a mensagem
                    ↓
        Anthropic entende que precisa:
        1. Buscar produtos (chama tool)
        2. Buscar políticas (chama RAG/pgvector)
                    ↓
        BuscarProdutos::handle() executa
        KnowledgeSearchTool busca no embeddings
                    ↓
        Resultados voltam pro Claude
                    ↓
        Claude monta resposta natural
                    ↓
    Histórico salvo no Redis (próxima pergunta tem contexto)
                    ↓
        Resposta entregue ao usuário
```

---

## ✅ Checklist Final

- [ ] Composer instalado
- [ ] Migrations rodadas
- [ ] `.env` configurado
- [ ] PostgreSQL com pgvector, ou store `database`/Qdrant no MySQL/MariaDB
- [ ] Redis rodando
- [ ] Tool customizada criada
- [ ] Documentos indexados
- [ ] Testado no tinker
- [ ] Error Recovery e Analytics avaliados (habilitados ou intencionalmente desligados)

Se tudo marcado → **PRONTO PARA PRODUÇÃO!**

---

## 🐛 Troubleshooting

### Erro com pgvector?
```bash
php artisan tinker
DB::select('CREATE EXTENSION IF NOT EXISTS vector');
exit
```

### Erro com Redis?
```bash
redis-cli ping  # deve retornar PONG
```

### Erro com API key?
```bash
php artisan config:cache
php artisan config:clear
```

### Ver logs detalhados?
```bash
tail -f storage/logs/laravel.log
```

### Servidor MCP não conecta?

- stdio: rode `php artisan agent-kit:mcp --path=/projeto < /dev/null` e leia o stderr; erros de inicialização retornam código 1.
- HTTP: confira `AGENT_KIT_MCP_HTTP_ENABLED=true`, `AGENT_KIT_MCP_BEARER_TOKEN` com 32+ caracteres e o header `Authorization: Bearer <token>`; `403` indica origin/host fora de `AGENT_KIT_MCP_ALLOWED_ORIGINS` ou cliente fora de loopback (`AGENT_KIT_MCP_ALLOW_REMOTE`); `503` indica configuração inválida (veja o log da aplicação).
- Detalhes em [MCP_SERVER.md](MCP_SERVER.md).

---

## 📖 Documentação Adicional

- Veja [README.md](README.md) para uso básico
- Veja [config/agent-kit.php](config/agent-kit.php) para todas as opções de configuração
- Leia o código em `src/` para entender a arquitetura
- Veja [REFACTORING_AGENT.md](REFACTORING_AGENT.md) para o Refactoring Agent e os comandos `agent-kit:refactor-*`
- Veja [ARCHITECTURE.md](ARCHITECTURE.md) para as decisões de persistência

---

## 🎯 Próximos Passos

1. **Crie mais Tools** - Adapte `BuscarProdutos` para seus casos de uso
2. **Indexe seus documentos** - Use `KnowledgeIndexer` para adicionar FAQs, políticas, manuais
3. **Monitore o uso** - Configure logging em `config/agent-kit.php`
4. **Otimize prompts** - Refine o `system()` prompt conforme testar

---

**Dúvidas?** Veja o código em `src/Agent.php` - está bem documentado!
