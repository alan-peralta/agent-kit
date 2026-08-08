# ⚡ Quick Start: Embedders

Guia rápido para usar cada embedder suportado.

---

## 🎯 Escolha seu embedder

### 1️⃣ OpenAI (Padrão - Recomendado)

```env
AGENT_EMBEDDER=openai
OPENAI_API_KEY=sk-xxxxx
OPENAI_EMBEDDER_MODEL=text-embedding-3-small
```

**Quando:** Qualidade garantida, caso geral  
**Custo:** $0.02 / 1M tokens  
**Dims:** 1536  

---

### 2️⃣ Mistral (Melhor custo-benefício)

```env
AGENT_EMBEDDER=mistral
MISTRAL_API_KEY=xxxxx
MISTRAL_EMBEDDER_MODEL=mistral-embed
```

**Quando:** Quer qualidade OpenAI com preço menor  
**Custo:** $0.10 / 1M tokens  
**Dims:** 1024  
**Multilíngue:** ✓  

---

### 3️⃣ Google Gemini (Grátis)

```env
AGENT_EMBEDDER=gemini
GEMINI_API_KEY=xxxxx
```

**Quando:** Orçamento zero, projeto pequeno  
**Custo:** GRÁTIS (1000 req/dia)  
**Dims:** 768  
**Multilíngue:** ✓  

---

### 4️⃣ Cohere (Máxima precisão)

```env
AGENT_EMBEDDER=cohere
COHERE_API_KEY=xxxxx
COHERE_EMBEDDER_MODEL=embed-english-v3.0
COHERE_EMBEDDER_INPUT_TYPE=search_document

# Para multilíngue:
# COHERE_EMBEDDER_MODEL=embed-multilingual-v3.0
```

**Quando:** Máxima qualidade, multilíngue  
**Custo:** $0.10 / 1M tokens  
**Dims:** 1024 ou 384 (light)  
**Multilíngue:** ✓  

---

### 5️⃣ AWS Titan (Enterprise)

```env
AGENT_EMBEDDER=aws
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=xxxxx
AWS_SECRET_ACCESS_KEY=xxxxx
AWS_EMBEDDER_MODEL=amazon.titan-embed-text-v2:0
```

**Quando:** Usa AWS, compliance/security  
**Custo:** $0.13 / 1M tokens (input)  
**Dims:** 1536  
**Multilíngue:** ✓  

**Requer:** `composer require aws/aws-sdk-php`

---

## 📋 Comparação rápida

| Embedder | Custo | Qualidade | Multilíngue | Dims | Tipo |
|----------|-------|-----------|-----------|------|------|
| OpenAI | 💰💰 | ⭐⭐⭐⭐⭐ | ❌ | 1536 | API |
| Mistral | 💰💰 | ⭐⭐⭐⭐ | ✓ | 1024 | API |
| Gemini | 💰 | ⭐⭐⭐⭐ | ✓ | 768 | API |
| Cohere | 💰💰💰 | ⭐⭐⭐⭐⭐ | ✓ | 1024 | API |
| AWS | 💰💰💰 | ⭐⭐⭐⭐ | ✓ | 1536 | API |

---

## 🔄 Mudar de embedder

Se quiser trocar, **re-indexe**:

```bash
# 1. Mude .env
AGENT_EMBEDDER=mistral

# 2. Re-indexe
php artisan knowledge:index

# 3. Teste
php artisan tinker
Agent::make()->knowledgeBase('faq')->send('teste');
exit
```

---

## 💡 Minhas recomendações

**Melhor qualidade:**
```env
AGENT_EMBEDDER=cohere
```

**Melhor custo-benefício:**
```env
AGENT_EMBEDDER=mistral
```

**Grátis:**
```env
AGENT_EMBEDDER=gemini
```

**Padrão (safe choice):**
```env
AGENT_EMBEDDER=openai
```

**Enterprise:**
```env
AGENT_EMBEDDER=aws
```

---

## 📖 Documentação completa

- [EMBEDDERS.md](EMBEDDERS.md) - Detalhes de cada embedder
- [EMBEDDERS_MARKET.md](EMBEDDERS_MARKET.md) - Análise de mercado
- [SETUP.md](SETUP.md) - Guia completo de configuração
