# 🏗️ Arquitetura: Redis vs Database vs Knowledge Base

Entender a diferença entre estes 3 é crítico para usar Agent Kit corretamente.

---

## 📊 Comparação Visual

```
┌──────────────────────────────────────────────────────────────────┐
│                                                                  │
│  USER MESSAGE                                                   │
│  "Qual é o horário? Quero um produto barato"                   │
│                                                                  │
└─────────────────┬────────────────────────────────────────────────┘
                  │
        ┌─────────▼────────────┐
        │   AGENT PROCESSING   │
        └─────────┬────────────┘
                  │
        ┌─────────┴─────────────────┬──────────────────────┬─────────┐
        │                           │                      │         │
        ▼                           ▼                      ▼         ▼
┌───────────────┐         ┌──────────────────┐    ┌─────────────┐
│  CONVERSAS    │         │  KNOWLEDGE BASE  │    │   TOOLS     │
│  (Redis/DB)   │         │  (pgvector)      │    │ (Custom)    │
├───────────────┤         ├──────────────────┤    ├─────────────┤
│               │         │                  │    │             │
│ Mensagem 1:   │         │ [embedding]      │    │ buscar_     │
│ "Oi"          │         │ FAQ: Horário...  │    │ produtos()  │
│               │         │ [embedding]      │    │             │
│ Mensagem 2:   │         │ FAQ: Entrega...  │    │ criar_      │
│ "Quero"       │         │ [embedding]      │    │ agendamento │
│               │         │ Política: Dev... │    │ ()          │
│ ...           │         │ [embedding]      │    │             │
│               │         │ ...              │    │             │
└───────────────┘         └──────────────────┘    └─────────────┘
        │                         │                      │
        └─────────────────────────┼──────────────────────┘
                                  │
                        ┌─────────▼─────────┐
                        │  CLAUDE RESPONSE  │
                        │ "Estamos abertos   │
                        │  de 9h às 18h.    │
                        │  Aqui estão os    │
                        │  produtos..."     │
                        └───────────────────┘
```

---

## 🔍 O que cada um faz?

### 1️⃣ **CONVERSAS (Redis ou Database)**

**Armazena:** Histórico de mensagens de cada conversa

**Quando usar:** Quando você quer que o agent "lembre" do que foi dito antes

**Formato:**
```
Conversa user_123:
├── Mensagem 1: "user" → "Oi, quero um notebook"
├── Mensagem 2: "assistant" → "Qual sua faixa de preço?"
├── Mensagem 3: "user" → "Até 5mil"
└── Mensagem 4: "assistant" → "Recomendo este aqui..."
```

**Redis vs Database:**
| Aspecto | Redis | Database |
|---------|-------|----------|
| Velocidade | ⚡ Ultra rápido | 🐢 Um pouco mais lento |
| Persistência | 🚨 Expira (30 dias default) | ✓ Permanente |
| Escalabilidade | ✓ Muito bem | ✓ Muito bem |
| Ideal para | Conversas ativas | Auditoria / Histórico |
| TTL | Automático | Manual (você deleta) |

**Você perde os dados?** SIM, após TTL (30 dias por padrão)

---

### 2️⃣ **KNOWLEDGE BASE (pgvector no PostgreSQL)**

**Armazena:** Documentos indexados (FAQ, políticas, manuais) com embeddings

**Quando usar:** Para dar contexto ao agent sobre informações estáticas

**Formato:**
```
Coleção "faq":
├── Chunk 1: [embedding] "Qual horário? Estamos de 9h-18h"
├── Chunk 2: [embedding] "Entrega? Entregamos em todo Brasil"
├── Chunk 3: [embedding] "Devoluções? 30 dias de garantia"

Coleção "politica":
├── Chunk 1: [embedding] "Política de privacidade: seus dados..."
├── Chunk 2: [embedding] "LGPD: direitos de acesso e exclusão..."
```

**Você perde os dados?** NÃO, fica permanentemente no PostgreSQL

---

### 3️⃣ **TOOLS (Customizadas)**

**Armazena:** Nada. Executa ações e retorna resultados

**Quando usar:** Para buscar dados do seu banco, executar ações

**Exemplo:**
```php
BuscarProdutos::handle()
└─→ SELECT * FROM products WHERE...
    └─→ Retorna dados ao agent
```

---

## 🎯 Fluxo de uma interação completa

```
User: "Qual é o seu horário e quero um notebook barato"
        │
        ├─→ [CONVERSA] Carrega histórico do Redis
        │   └─→ "Você perguntou sobre notebooks antes?"
        │
        ├─→ [KNOWLEDGE BASE] Busca no pgvector
        │   └─→ Encontra: "Horários: 9h-18h"
        │
        ├─→ [TOOL] Executa BuscarProdutos
        │   └─→ SELECT FROM products WHERE price < 5000
        │
        └─→ Claude monta a resposta com tudo
            │
            └─→ "Estamos de 9h-18h.
                 Aqui estão os notebooks:
                 1. XYZ - R$4.500"
                 │
                 └─→ [CONVERSA] Salva essa resposta no Redis
                     (próxima pergunta vai ter contexto)
```

---

## ⚠️ Três cenários importantes

### Cenário 1: Você perde a conversa no Redis (TTL expirou)

```
Timeline:
Day 1: User faz pergunta → Conversa salva no Redis por 30 dias
Day 31: TTL expira, Redis deleta tudo
Day 32: Mesmo user volta → Agent NÃO sabe do que foi conversado

SOLUÇÃO: Se precisa auditoria permanente, use DatabaseStore em vez de Redis
```

### Cenário 2: Documentos na Knowledge Base (FAQ)

```
Você indexa FAQ em Janeiro:
  "Q: Vocês entregam em qual região?
   R: Entregamos em todo Brasil"

Chega Junho (muda política):
  "Agora só entregamos no Sudeste"

MAS: A FAQ indexada ainda diz "todo Brasil"!

SOLUÇÃO: Quando mudar política, re-indexe:
  php artisan knowledge:index
```

### Cenário 3: Combinar tudo

```
User (no dia 1):
  "Quero um produto e saber a política"
  
  ├─→ [CONVERSA] Vazio (primeira vez)
  ├─→ [KNOWLEDGE BASE] Retorna política do FAQ
  ├─→ [TOOL] Busca produtos
  └─→ Agent responde

User (no dia 2, mesma conversa):
  "E qual era aquele que vc recomendou ontem?"
  
  ├─→ [CONVERSA] Carrega do Redis: "Ontem recomendei XYZ"
  │   (Claude sabe do contexto anterior!)
  ├─→ [KNOWLEDGE BASE] Não precisa agora
  └─→ Agent responde com contexto
```

---

## 🏗️ Recomendação por caso de uso

### Chatbot de E-commerce
```php
// Faça assim:
->conversation("user_{$id}")      // Redis = conversas rápidas
->knowledgeBase('faq')             // pgvector = FAQ estática
->tools([BuscarProdutos::class])  // Tools = buscar DB
```
**Por quê?**
- Conversas ativas = Redis (rápido)
- FAQ não muda todo dia = pgvector (permanente)

---

### Assistente de Suporte (com auditoria legal)
```php
// Faça assim (configure antes):
// 'conversation' => ['driver' => 'database']  // no config

->conversation("ticket_{$id}")     // Database = auditoria
->knowledgeBase('politicas')       // pgvector = políticas legais
->tools([BuscarTicket::class])    // Tools = buscar dados
```
**Por quê?**
- Precisa manter conversa 5+ anos = Database (permanente)
- Políticas legais = pgvector (não expira)

---

### Bot de IA para resumir emails
```php
// Faça assim:
->system('...')                    // Sem conversation() = sem histórico
->knowledgeBase('manual')          // pgvector = contexto de email
->send($email_body)
```
**Por quê?**
- Cada email é independente = sem conversa
- Precisa contexto = knowledge base

---

## 🚨 Checklist de decisão

**Você precisa usar Conversa (Redis/DB)?**
- [ ] Próxima mensagem do user precisa "lembrar" da anterior?
- [ ] É um chatbot onde o context importa?
- [ ] Quer historico?

**Você precisa usar Knowledge Base (pgvector)?**
- [ ] Tem FAQ/documentos estáticos?
- [ ] Quer que o agent consulte políticas/manuais?
- [ ] Informações não mudam diariamente?

**Você precisa usar Tools?**
- [ ] Precisa buscar dados do seu banco?
- [ ] Precisa executar ações (criar, deletar, etc)?
- [ ] Dados mudam em tempo real?

---

## 💾 Estratégia de Persistência Recomendada

**Para produção, use assim:**

```
┌─────────────────────────────────────────────────────────┐
│  CONVERSAS ATIVAS                                       │
│  └─→ Redis (rápido, expira em 30 dias)                 │
│      └─→ OK perder? Sim                                │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  CONVERSAS COM AUDITORIA (suporte, financeiro)        │
│  └─→ Database (lento, permanente)                      │
│      └─→ OK perder? Não, precisa 5+ anos             │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  DOCUMENTOS ESTÁTICOS (FAQ, políticas)                 │
│  └─→ pgvector (busca semântica)                       │
│      └─→ OK perder? Não, são documentos legais       │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  DADOS DINÂMICOS (produtos, pedidos)                   │
│  └─→ Seus models Laravel (Product, Order)             │
│      └─→ Já tem suas tools apontando pra eles        │
└─────────────────────────────────────────────────────────┘
```

---

## 🎓 Resumo Final

| Componente | O que é | Você perde? | Quando usar |
|-----------|---------|-----------|-----------|
| **Redis (Conversa)** | Histórico de msgs | ✓ Sim (30 dias) | Chats ativos |
| **Database (Conversa)** | Histórico de msgs | ✗ Não (permanente) | Auditoria |
| **pgvector (Knowledge)** | Docs indexados | ✗ Não (permanente) | FAQ/contexto |
| **Tools** | Ações/queries | N/A | Buscar dados |

**Na dúvida:**
- Precisa lembrar de conversas antigas? → Database
- É só contexto adicional? → Knowledge Base + Redis
- Precisa executar ações? → Tools

---

## 🔗 Próximas Leituras

- Ver [SETUP.md](SETUP.md) para configuração prática
- Ver [src/Conversation/](src/Conversation/) para drivers
- Ver [src/Knowledge/](src/Knowledge/) para RAG
