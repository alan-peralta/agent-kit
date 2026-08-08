# 🚀 Guia Completo de Configuração - Agent Kit + RAG

Configuração passo a passo para usar Agent Kit com PostgreSQL + Redis + RAG (Knowledge Base).

## ✅ Pré-requisitos

- Laravel 10+ (suporta 10, 11, 12)
- PHP 8.2+
- PostgreSQL com extensão pgvector
- Redis
- Uma API de LLM (OpenAI, Anthropic, Gemini ou DeepSeek)

---

## 📋 Passo 1: Instalar o pacote

```bash
composer require peralta/agent-kit
php artisan vendor:publish --tag=agent-kit-config
php artisan vendor:publish --tag=agent-kit-migrations
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

# Knowledge Base (PostgreSQL + pgvector)
AGENT_KNOWLEDGE_STORE=pgvector
AGENT_KNOWLEDGE_DB=pgsql
AGENT_EMBEDDER=openai

# Logging (opcional)
AGENT_LOGGING=true
AGENT_LOG_CHANNEL=stack
```

### Opções de Provider:
- `openai` - GPT-4, GPT-4o
- `anthropic` - Claude
- `gemini` - Google Gemini
- `deepseek` - DeepSeek

---

## 🗄️ Passo 3: Rodar as migrations

```bash
php artisan migrate
```

Isso cria:
1. **Tabela `agent_messages`** - Histórico de mensagens
2. **Tabela `knowledge_chunks`** - Documentos indexados para RAG
3. **Extensão `pgvector`** no PostgreSQL - Para busca semântica

---

## ✔️ Passo 4: Verificar pgvector

Confirme que pgvector foi instalado corretamente:

```bash
php artisan tinker

# Dentro do tinker:
DB::select('CREATE EXTENSION IF NOT EXISTS vector');
DB::select('SELECT 1 FROM pg_extension WHERE extname = "vector"');
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
- [ ] PostgreSQL com pgvector
- [ ] Redis rodando
- [ ] Tool customizada criada
- [ ] Documentos indexados
- [ ] Testado no tinker

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

---

## 📖 Documentação Adicional

- Veja [README.md](README.md) para uso básico
- Veja [config/agent-kit.php](config/agent-kit.php) para todas as opções de configuração
- Leia o código em `src/` para entender a arquitetura

---

## 🎯 Próximos Passos

1. **Crie mais Tools** - Adapte `BuscarProdutos` para seus casos de uso
2. **Indexe seus documentos** - Use `KnowledgeIndexer` para adicionar FAQs, políticas, manuais
3. **Monitore o uso** - Configure logging em `config/agent-kit.php`
4. **Otimize prompts** - Refine o `system()` prompt conforme testar

---

**Dúvidas?** Veja o código em `src/Agent.php` - está bem documentado!
