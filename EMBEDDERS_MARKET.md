# 🌍 Embedders do Mercado: Guia Completo 2025

Análise dos principais embedders disponíveis, características, custos e quando usar cada um.

---

## 📊 Tabela Comparativa Completa

| Embedder | Empresa | Dimensões | Custo | Qualidade | Velocidade | Multilíngue | Status |
|----------|---------|-----------|-------|-----------|-----------|-----------|--------|
| **text-embedding-3-small** | OpenAI | 1536 | 💰💰 | ⭐⭐⭐⭐⭐ | Rápido | ❌ | ✓ Implementado |
| **text-embedding-3-large** | OpenAI | 3072 | 💰💰💰 | ⭐⭐⭐⭐⭐ | Rápido | ❌ | Disponível |
| **embedding-001** | Google Gemini | 768 | 💰 | ⭐⭐⭐⭐ | Médio | ✓ | ✓ Implementado |
| **embed-english-v3.0** | Cohere | 1024 | 💰💰💰 | ⭐⭐⭐⭐⭐ | Rápido | ❌ | ✓ Implementado |
| **embed-multilingual-v3.0** | Cohere | 1024 | 💰💰💰 | ⭐⭐⭐⭐⭐ | Rápido | ✓ | Disponível |
| **mistral-embed** | Mistral | 1024 | 💰💰 | ⭐⭐⭐⭐ | Rápido | ✓ | 🔜 Próximo |
| **Titan Embeddings V2** | AWS | 1536 | 💰💰 | ⭐⭐⭐⭐ | Rápido | ✓ | 🔜 Próximo |
| **text-embedding-ada-002** | OpenAI | 1536 | 💰 | ⭐⭐⭐ | Médio | ❌ | Legado |
| **all-MiniLM-L6-v2** | Sentence Transformers | 384 | 💰 (grátis) | ⭐⭐⭐ | Muito rápido | ❌ | 🔜 Próximo |
| **bge-large-en-v1.5** | BAAI (HuggingFace) | 1024 | 💰 (grátis) | ⭐⭐⭐⭐ | Rápido | ✓ | 🔜 Próximo |
| **e5-large-v2** | Microsoft | 1024 | 💰 (grátis) | ⭐⭐⭐⭐ | Médio | ✓ | 🔜 Próximo |
| **jina-embeddings-v2** | Jina AI | 768 | 💰💰 | ⭐⭐⭐⭐ | Muito rápido | ✓ | 🔜 Próximo |
| **nomic-embed-text-v1.5** | Nomic | 768 | 💰 (grátis) | ⭐⭐⭐⭐ | Muito rápido | ✓ | 🔜 Próximo |
| **UAE-Large-V1** | Alibaba | 1024 | 💰 (grátis) | ⭐⭐⭐⭐ | Rápido | ✓ | 🔜 Próximo |

---

## 🏆 Top 5 Recomendados

### 1️⃣ **OpenAI: text-embedding-3-small** ⭐⭐⭐⭐⭐

**Dimensões:** 1536  
**Custo:** $0.02 por 1M tokens  
**Qualidade:** Melhor do mercado  
**Velocidade:** ~100ms  

**Quando usar:**
- ✅ Produção (qualidade garantida)
- ✅ Já usa OpenAI pra LLM
- ✅ Orçamento ok
- ✅ Idioma: English-first

```env
AGENT_EMBEDDER=openai
OPENAI_EMBEDDER_MODEL=text-embedding-3-small
```

---

### 2️⃣ **Mistral: mistral-embed** ⭐⭐⭐⭐

**Dimensões:** 1024  
**Custo:** $0.10 por 1M tokens  
**Qualidade:** Excelente (benchmarks: MTEB #3)  
**Velocidade:** ~100ms  
**Multilíngue:** ✓ Sim  

**Quando usar:**
- ✅ Qualidade próxima do OpenAI
- ✅ Multilíngue
- ✅ Preço competitivo
- ✅ Já usa Mistral pra LLM

```env
AGENT_EMBEDDER=mistral
MISTRAL_API_KEY=xxxxx
```

---

### 3️⃣ **Cohere: embed-multilingual-v3.0** ⭐⭐⭐⭐⭐

**Dimensões:** 1024  
**Custo:** $0.10 por 1M tokens  
**Qualidade:** Melhor em multilíngue  
**Velocidade:** ~100ms  
**Multilíngue:** ✓ 100+ idiomas  

**Quando usar:**
- ✅ App multilíngue
- ✅ Precisa máxima precisão
- ✅ Benchmarks: MTEB #1
- ✅ Production-ready

```env
AGENT_EMBEDDER=cohere
COHERE_EMBEDDER_MODEL=embed-multilingual-v3.0
```

---

### 4️⃣ **Google Gemini: embedding-001** ⭐⭐⭐⭐

**Dimensões:** 768  
**Custo:** GRÁTIS (1000 req/dia free tier)  
**Qualidade:** Boa (MTEB #8)  
**Velocidade:** ~150ms  
**Multilíngue:** ✓ ~100 idiomas  

**Quando usar:**
- ✅ Orçamento zero
- ✅ Projeto small/hobby
- ✅ Testing/prototyping
- ✅ Multilíngue

```env
AGENT_EMBEDDER=gemini
GEMINI_API_KEY=xxxxx
```

---

### 5️⃣ **Open Source: bge-large-en-v1.5** ⭐⭐⭐⭐

**Dimensões:** 1024  
**Custo:** GRÁTIS (self-hosted)  
**Qualidade:** Excelente (MTEB #2)  
**Velocidade:** ~50-100ms  
**Multilíngue:** ✓ Sim  

**Quando usar:**
- ✅ Zero custos
- ✅ Privacy/compliance
- ✅ Self-hosted
- ✅ Enterprise on-premise

```python
# Roda localmente via HuggingFace Inference Server
AGENT_EMBEDDER=huggingface
HUGGINGFACE_MODEL=BAAI/bge-large-en-v1.5
HUGGINGFACE_API_URL=http://localhost:8000
```

---

## 💰 Custo Estimado (10M tokens/mês)

| Embedder | Custo | Observação |
|----------|-------|-----------|
| Mistral | $1/mês | Melhor custo-benefício |
| Cohere | $1/mês | Multilíngue premium |
| OpenAI | $0.20/mês | Padrão industry |
| Gemini | $0 | Grátis até 1000 req/dia |
| Open Source | $0 | + custo de servidor |

---

## 📈 Qualidade (MTEB Ranking)

Benchmark "Massive Text Embedding Benchmark" (2025):

```
1. 🥇 Cohere: embed-multilingual-v3.0 (SCORE: 64.8)
2. 🥈 BGE-large-en-v1.5 (SCORE: 63.2)
3. 🥉 Mistral: mistral-embed (SCORE: 62.7)
...
8.    Google Gemini: embedding-001 (SCORE: 59.1)
...
13.   OpenAI: text-embedding-3-small (SCORE: 57.4)*

*OpenAI prioriza eficiência sobre MTEB score
```

---

## 🎯 Matriz de Decisão

```
┌─────────────────────────────────────────────┐
│ Qual seu caso de uso?                       │
└──────────────┬────────────────────────────┐ │
               │                            │ │
               ▼                            ▼ │
        ┌──────────────┐           ┌────────────────┐
        │ Multilíngue? │           │ Orçamento?     │
        └──┬───────┬──┘            └──┬───────┬────┘
           │       │                  │       │
        Sim│       │Não              Zero│    │Tem
           │       │                  │      │
           ▼       ▼                  ▼      ▼
        ┌──────────┐              ┌─────┐ ┌─────┐
        │ Cohere   │  ┐           │Gem  │ │OpenAI│
        │ v3 Multi │  │ Custoso   │ini  │ │ ou  │
        │ ou E5    │  └─────┐     │ ou  │ │Mist │
        │ BGE      │        │     │Nomic│ │ral  │
        └──────────┘        │     └─────┘ └─────┘
                            │
                    ┌───────▼────────┐
                    │ OpenAI (se ok) │
                    │ Mistral (bom)  │
                    │ Cohere v3.0    │
                    └────────────────┘
```

---

## 🔧 Guia de Implementação

### Já temos:
- ✓ OpenAI text-embedding-3-small
- ✓ Google Gemini embedding-001
- ✓ Cohere embed-english-v3.0

### Próximos (recomendo adicionar):

**Prioridade Alta:**
1. Mistral embed (Melhor custo-benefício)
2. Cohere multilingual (Melhor multilíngue)

**Prioridade Média:**
3. AWS Titan (Enterprise/AWS users)
4. E5-large (Open source)
5. BGE (Open source, melhor qualidade)

**Nice-to-have:**
6. Jina embeddings (Muito rápido)
7. Nomic (Grátis, rápido)
8. Llama 2 embeddings (Self-hosted)

---

## 🚀 Roadmap Sugerido

```
Fase 1 (Agora):
✓ OpenAI
✓ Gemini
✓ Cohere

Fase 2 (Próximas 2 semanas):
→ Mistral embed
→ Cohere multilingual
→ HuggingFace (E5 / BGE)

Fase 3 (Próximas 4 semanas):
→ AWS Titan
→ Jina
→ Self-hosted options

Fase 4 (Maintenance):
→ Monitorar MTEB benchmarks
→ Atualizar modelos conforme melhoram
→ Adicionar novos conforme lançam
```

---

## 📋 Checklist: Escolher embedder

**Performance:**
- [ ] Qual é a latência aceitável? (<100ms ideal)
- [ ] Quantos documentos vai indexar? (10k, 100k, 1M+)
- [ ] Qual frequência de atualização? (diário, horário, real-time)

**Custo:**
- [ ] Qual orçamento mensal máximo?
- [ ] Paga por request ou por volume?
- [ ] Tem free tier?

**Qualidade:**
- [ ] Qual nível de precision precisa? (70%, 85%, 95%)
- [ ] Idiomas envolvidos? (1, 5, 10+)
- [ ] Domínios específicos? (legal, medical, tech)

**Infraestrutura:**
- [ ] Cloud vs Self-hosted?
- [ ] Dependência de terceiros aceitável?
- [ ] Compliance/privacy requerido?

---

## 🔗 Links Úteis

**APIs Comerciais:**
- OpenAI: https://platform.openai.com/docs/guides/embeddings
- Mistral: https://docs.mistral.ai/capabilities/embeddings/
- Cohere: https://docs.cohere.com/reference/embed
- Google Gemini: https://ai.google.dev
- AWS Titan: https://docs.aws.amazon.com/bedrock/
- Jina: https://jina.ai/embeddings/

**Open Source:**
- Sentence Transformers: https://www.sbert.net/
- HuggingFace: https://huggingface.co/models?pipeline_tag=sentence-similarity
- BGE: https://huggingface.co/BAAI/bge-large-en-v1.5
- E5: https://huggingface.co/intfloat/e5-large-v2

**Benchmarks:**
- MTEB Leaderboard: https://huggingface.co/spaces/mteb/leaderboard
- Text Embedding Benchmark: https://github.com/embeddings-benchmark

---

## 🎓 Próximas implementações

Recomendo implementar nesta ordem:

### 1. Mistral (High Priority)
```env
AGENT_EMBEDDER=mistral
MISTRAL_API_KEY=xxxxx
MISTRAL_EMBEDDER_MODEL=mistral-embed
```

### 2. AWS Titan (Enterprise)
```env
AGENT_EMBEDDER=aws
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=xxxxx
AWS_SECRET_ACCESS_KEY=xxxxx
```

### 3. HuggingFace (Self-hosted)
```env
AGENT_EMBEDDER=huggingface
HUGGINGFACE_MODEL=BAAI/bge-large-en-v1.5
HUGGINGFACE_API_URL=http://localhost:8000
```

---

## ✅ Conclusão

**Se tiver que escolher UMA agora:**

```
┌───────────────────────────────────┐
│ MISTRAL embed                     │
├───────────────────────────────────┤
│ ✓ Qualidade quase OpenAI          │
│ ✓ Preço competitivo               │
│ ✓ Multilíngue                     │
│ ✓ Benchmarks top 3                │
│ ✓ Growing ecosystem               │
└───────────────────────────────────┘
```

Pronto para implementar mais embedders! 🚀
