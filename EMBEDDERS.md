# 🔗 Embedders: Guia Completo

Embedders são modelos que convertem texto em vetores numéricos para busca semântica no RAG.

---

## 📊 Comparação dos Embedders

| Critério | OpenAI | Gemini | Cohere |
|----------|--------|--------|--------|
| **Dimensões** | 1536 | 768 | 1024 (ou 384) |
| **Velocidade** | Rápido | Muito rápido | Rápido |
| **Custo** | 💰💰 | 💰 | 💰💰💰 |
| **Qualidade** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ |
| **Multilíngue** | ❌ Inglês | ✓ ~100 idiomas | ✓ Sim |
| **Caso ideal** | Geral | Rápido e barato | Alta precisão |

---

## 🚀 OpenAI Embeddings

### Modelo: `text-embedding-3-small`

**Dimensões:** 1536  
**Custo:** $0.02 por 1M tokens (mais barato do OpenAI)  
**Velocidade:** ~50-100ms por batch

**Quando usar:**
- ✅ Caso de uso geral (padrão)
- ✅ Bom custo-benefício
- ✅ Qualidade superior
- ✅ Já tem crédito OpenAI

**Configuração:**

```env
AGENT_EMBEDDER=openai
OPENAI_API_KEY=sk-xxxxx
OPENAI_EMBEDDER_MODEL=text-embedding-3-small
OPENAI_EMBEDDER_DIMENSIONS=1536
```

**Uso no código:**

```php
$response = Agent::make()
    ->knowledgeBase('faq')  // Usa OpenAI automaticamente
    ->send('...');
```

---

## 🌐 Google Gemini Embeddings

### Modelo: `embedding-001`

**Dimensões:** 768 (fixo)  
**Custo:** GRÁTIS (até 1000 chamadas/dia com free tier)  
**Velocidade:** ~100-200ms por texto

**Quando usar:**
- ✅ Orçamento muito limitado
- ✅ Projeto pequeno/hobby
- ✅ Multilíngue importante
- ✅ Quer testar sem gastar

**Limitações:**
- ❌ Mais lento (processa um por vez)
- ❌ Qualidade um pouco inferior ao OpenAI
- ❌ Dimensões fixas (768)

**Configuração:**

```env
AGENT_EMBEDDER=gemini
GEMINI_API_KEY=xxxxx
```

**Uso no código:**

```php
$response = Agent::make()
    ->knowledgeBase('faq')  // Usa Gemini automaticamente
    ->send('...');
```

**Obtendo a API Key:**
```
1. Ir para: https://ai.google.dev
2. Clique "Get API Key"
3. Create API key in new project
4. Copie e coloque no .env
```

---

## 🔷 Cohere Embeddings

### Modelos:
- `embed-english-v3.0` - 1024 dimensões (mais preciso)
- `embed-english-light-v3.0` - 384 dimensões (mais rápido)
- `embed-multilingual-v3.0` - 1024 dimensões (multilíngue)

**Custo:** $0.10 por 1M tokens  
**Velocidade:** ~100ms por batch  
**Qualidade:** Superior ao OpenAI em muitos benchmarks

**Quando usar:**
- ✅ Precisa de máxima precisão
- ✅ Dados multilíngues
- ✅ Busca semântica complexa
- ✅ Projeto enterprise

**Configuração:**

```env
AGENT_EMBEDDER=cohere
COHERE_API_KEY=xxxxx
COHERE_EMBEDDER_MODEL=embed-english-v3.0
```

**Uso no código:**

```php
$response = Agent::make()
    ->knowledgeBase('faq')  // Usa Cohere automaticamente
    ->send('...');
```

---

## 🎯 Qual embedder escolher?

### Cenário 1: E-commerce (português)
```env
# Recomendação: OpenAI (bom custo-benefício)
AGENT_EMBEDDER=openai
```
**Por quê?** Qualidade superior, suporta português bem, preço justo.

---

### Cenário 2: Startup com orçamento 0
```env
# Recomendação: Gemini (grátis)
AGENT_EMBEDDER=gemini
```
**Por quê?** Grátis, funciona bem, multilíngue.

---

### Cenário 3: Suporte técnico (alta precisão)
```env
# Recomendação: Cohere (melhor qualidade)
AGENT_EMBEDDER=cohere
COHERE_EMBEDDER_MODEL=embed-english-v3.0
```
**Por quê?** Melhor precisão, suporta consultas complexas.

---

### Cenário 4: App multilíngue (10+ idiomas)
```env
# Recomendação: Cohere multilíngue
AGENT_EMBEDDER=cohere
COHERE_EMBEDDER_MODEL=embed-multilingual-v3.0
```
**Por quê?** Suporte nativo para 100+ idiomas.

---

### Cenário 5: Já usa OpenAI pra LLM
```env
# Recomendação: OpenAI (consolidar provider)
AGENT_EMBEDDER=openai
```
**Por quê?** Um provider, uma API key, uma fatura.

---

## 💰 Estimativa de Custos

Supondo 10.000 documentos de 500 palavras cada (5M tokens totais):

| Embedder | Custo inicial | Custo mensal* |
|----------|-----------|-----------|
| OpenAI | $0.10 | $0.10 (indexação) |
| Gemini | $0 | $0 (free tier) |
| Cohere | $0.50 | $0.50 (indexação) |

*Busca em RAG não custa (embeddings pré-calculados)

---

## 🔄 Migrando entre embedders

### ⚠️ Importante: Dimensões devem ser iguais

Cada embedder retorna dimensões diferentes:
- OpenAI: 1536
- Gemini: 768
- Cohere v3.0: 1024
- Cohere light: 384

**Se mudar embedder, precisa re-indexar tudo:**

```php
php artisan knowledge:index  // Deleta velhos e re-indexa
```

**Processo seguro:**

```bash
# 1. Backup do Database
mysqldump agent-kit > backup.sql

# 2. Mude o embedder no .env
AGENT_EMBEDDER=cohere

# 3. Re-indexe
php artisan knowledge:index

# 4. Teste RAG
php artisan tinker
Agent::make()->knowledgeBase('faq')->send('teste');
```

---

## 🧪 Testar um embedder

```bash
php artisan tinker

use Peralta\AgentKit\Knowledge\Embedders\OpenAIEmbedder;
use Peralta\AgentKit\Knowledge\Embedders\GeminiEmbedder;
use Peralta\AgentKit\Knowledge\Embedders\CohereEmbedder;

# OpenAI
$openai = new OpenAIEmbedder(config('agent-kit.knowledge.embedders.openai'));
$emb = $openai->embed('Olá mundo');
count($emb);  # 1536

# Gemini
$gemini = new GeminiEmbedder(config('agent-kit.knowledge.embedders.gemini'));
$emb = $gemini->embed('Olá mundo');
count($emb);  # 768

# Cohere
$cohere = new CohereEmbedder(config('agent-kit.knowledge.embedders.cohere'));
$emb = $cohere->embed('Olá mundo');
count($emb);  # 1024

exit
```

---

## ⚡ Performance: Batch vs Single

**Batch (recomendado para indexação):**
```php
$embedder->embedBatch(['text1', 'text2', 'text3']);
// 100ms para 3 textos
```

**Single (pior performance):**
```php
$embedder->embed('text1');
$embedder->embed('text2');
$embedder->embed('text3');
// 300ms para 3 textos (3x mais lento)
```

**O KnowledgeIndexer já usa batch automaticamente! ✓**

---

## 🛠️ Implementar seu próprio embedder

Se nenhum atender, é fácil criar:

```php
namespace App\Knowledge\Embedders;

use Peralta\AgentKit\Knowledge\Contracts\Embedder;

class MeuEmbedder implements Embedder
{
    public function embed(string $text): array
    {
        // Sua implementação
        return [...];
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn($t) => $this->embed($t), $texts);
    }

    public function dimensions(): int
    {
        return 1536;  // Suas dimensões
    }
}
```

Depois registre no ServiceProvider e use!

---

## 📚 Links úteis

- [OpenAI Embeddings](https://platform.openai.com/docs/guides/embeddings)
- [Google Gemini API](https://ai.google.dev)
- [Cohere Embeddings](https://docs.cohere.com/reference/embed)

---

## ✅ Checklist: Escolher embedder

- [ ] Qual seu orçamento? (grátis, barato, premium)
- [ ] Precisa de multilíngue?
- [ ] Qual qualidade de busca precisa?
- [ ] Já tem API key de algum provider?
- [ ] Configurou `.env` corretamente?
- [ ] Rodou migrations?
- [ ] Testou no tinker?

Pronto! Escolha seu embedder e comece a usar RAG! 🚀
