# 🚀 Hybrid Storage: Redis + Database

## O que é?

**Hybrid Storage** é uma arquitetura de dupla camada:

```
┌──────────────────────────────────────────────────────────┐
│  APLICAÇÃO                                               │
│  (Agent enviando mensagens)                             │
└──────────────────────┬───────────────────────────────────┘
                       │
         ┌─────────────▼──────────────┐
         │   HYBRID STORAGE           │
         │   (gerencia ambos)         │
         └──────┬──────────┬──────────┘
                │          │
         ┌──────▼──┐  ┌───▼─────────┐
         │  REDIS  │  │  DATABASE   │
         ├─────────┤  ├─────────────┤
         │ Rápido  │  │ Permanente  │
         │ Expira  │  │ Auditoria   │
         │ 30 dias │  │ Backup      │
         └─────────┘  └─────────────┘
```

## Por que usar?

### ✅ Benefícios

| Aspecto | Benefício |
|---------|-----------|
| **Performance** | Redis é super rápido (ms) |
| **Auditoria** | Database mantém histórico permanente |
| **Confiabilidade** | Se Redis cair, recupera do Database |
| **Escalabilidade** | Redis aguenta picos, Database persiste |
| **Compliance** | Cumpre requisitos legais (histórico 5+ anos) |

### 🔄 Como funciona

1. **Leitura**: Tenta Redis primeiro (rápido), se não achar vai ao Database
2. **Escrita**: Salva em AMBOS simultaneamente
3. **Fallback**: Se Redis cair, funciona só com Database (mais lento)

---

## 🔧 Configuração

### Passo 1: Configurar `.env`

```env
# Usar hybrid storage
AGENT_CONVERSATION_DRIVER=hybrid

# Redis (cache quente)
AGENT_CONVERSATION_REDIS=default
AGENT_CONVERSATION_REDIS_PREFIX=agent:conv:
AGENT_CONVERSATION_REDIS_TTL=2592000  # 30 dias (em segundos)

# Database (auditoria)
AGENT_CONVERSATION_DB=pgsql  # ou mysql
```

### TTL do Redis: Exemplos comuns

```env
# 1 hora
AGENT_CONVERSATION_REDIS_TTL=3600

# 1 dia
AGENT_CONVERSATION_REDIS_TTL=86400

# 7 dias
AGENT_CONVERSATION_REDIS_TTL=604800

# 30 dias (padrão)
AGENT_CONVERSATION_REDIS_TTL=2592000

# 90 dias
AGENT_CONVERSATION_REDIS_TTL=7776000
```

### Passo 2: Rodar migrations

```bash
php artisan migrate
```

Cria a tabela `agent_messages` automaticamente.

### Passo 3: Usar normalmente

```php
$response = Agent::make()
    ->conversation("user_123")  // Usa hybrid automaticamente
    ->send("Olá!");

// Tudo já está sendo salvo em ambos!
```

---

## 📊 Fluxo detalhado

### Primeira interação

```
User: "Olá, qual é o horário?"
        │
        ├─→ [HYBRID] Procura no Redis
        │   └─→ ❌ Vazio (primeira vez)
        │
        ├─→ [HYBRID] Procura no Database
        │   └─→ ❌ Vazio
        │
        ├─→ [AGENT] Responde
        │
        └─→ [HYBRID] Salva em AMBOS
            ├─→ ✓ Redis: "agente:conv:user_123" (30 dias)
            └─→ ✓ Database: "agent_messages" (permanente)
```

### Segunda interação (2 minutos depois)

```
User: "E qual é o horário de entrega?"
        │
        ├─→ [HYBRID] Procura no Redis
        │   └─→ ✓ Encontrou! (rápido, <1ms)
        │       Mensagem anterior: "qual é o horário?"
        │
        ├─→ [AGENT] Usa contexto
        │   └─→ Sabe que o user perguntou antes
        │
        └─→ [HYBRID] Salva em AMBOS
            ├─→ ✓ Redis
            └─→ ✓ Database
```

### Terceira interação (35 dias depois, Redis expirou)

```
User: "Vocês entregam no meu bairro?"
        │
        ├─→ [HYBRID] Procura no Redis
        │   └─→ ❌ Expirou (30 dias)
        │
        ├─→ [HYBRID] Procura no Database
        │   └─→ ✓ Encontrou tudo!
        │       Mensagens de 35 dias atrás
        │
        ├─→ [AGENT] Usa contexto antigo
        │   └─→ Sabe que o user perguntou sobre horário e entrega
        │
        └─→ [HYBRID] Salva em AMBOS
            ├─→ ✓ Redis (novo TTL de 30 dias)
            └─→ ✓ Database (permanente)
```

---

## 🎯 Casos de uso

### E-commerce
```php
// Use hybrid para histórico de compras + performance
AGENT_CONVERSATION_DRIVER=hybrid
```
- User busca produto
- Agent lembra: "Você procurou por notebook mês passado"
- Performance: Redis responde em ms

---

### Suporte técnico (com SLA)
```php
// Use hybrid para auditoria + compliance
AGENT_CONVERSATION_DRIVER=hybrid
// Manter histórico 3 anos? Tá no Database!
```

---

### SaaS multi-tenant
```php
// Use hybrid para cada tenant
->conversation("tenant_1_user_123")  // Isolado no Redis + Database
->conversation("tenant_2_user_456")  // Outro isolado
```

---

## ⚙️ Configuração Avançada

### Via `.env` (recomendado)

```env
# Customize Redis
AGENT_CONVERSATION_REDIS=cache  # Outro Redis?
AGENT_CONVERSATION_REDIS_PREFIX=chat:
AGENT_CONVERSATION_REDIS_TTL=604800  # 7 dias

# Customize Database
AGENT_CONVERSATION_DB=mysql  # Outro DB?
```

### Via `config/agent-kit.php` (hardcoded)

```php
'conversation' => [
    'driver' => 'hybrid',
    'drivers' => [
        'hybrid' => [
            'redis_connection' => 'cache',
            'redis_prefix' => 'chat:',
            'redis_ttl' => 60 * 60 * 24 * 7,  // 7 dias
            'db_connection' => 'mysql',
            'messages_table' => 'chat_history',
        ],
    ],
],
```

### Converter segundos em dias (referência rápida)

```
3600      = 1 hora
86400     = 1 dia
604800    = 1 semana (7 dias)
2592000   = 1 mês (30 dias)
7776000   = 3 meses (90 dias)
15552000  = 6 meses (180 dias)
31536000  = 1 ano (365 dias)
```

---

## 🔍 Monitorar o que está salvo

### Ver no Redis

```bash
redis-cli
> KEYS "agent:conv:*"
> LRANGE agent:conv:user_123 0 -1
```

### Ver no Database

```php
php artisan tinker

DB::table('agent_messages')
    ->where('conversation_id', 'user_123')
    ->orderBy('id')
    ->get();
```

---

## 🐛 Troubleshooting

### Redis caiu, mas quero continuar?

Não precisa fazer nada! Hybrid automaticamente usa o Database como fallback.

```
┌─ Redis CAIU ─┐
└─ Usa Database (mais lento, mas funciona) ─┘
└─ Quando Redis volta, sincroniza automaticamente
```

### Database cheio, histórico muito grande?

Adicione uma rotina de cleanup:

```php
// app/Console/Commands/CleanupConversationHistory.php
DB::table('agent_messages')
    ->where('created_at', '<', now()->subYears(5))
    ->delete();
```

Execute via scheduler:

```php
// app/Console/Kernel.php
$schedule->command('cleanup:conversations')
    ->daily();
```

---

## 📈 Performance esperada

| Operação | Redis | Database | Hybrid |
|----------|-------|----------|--------|
| Primeira carga | - | 50-100ms | 50-100ms (1x Database) |
| Cargas seguintes | <1ms | 50-100ms | <1ms (1x Redis) |
| Escrita | <1ms | 20-50ms | <1ms + 20-50ms (paralelo) |

**Resultado**: Praticamente a velocidade do Redis com a durabilidade do Database!

---

## 🔐 Segurança

- **Redis**: Mensagens expiram em 30 dias (segurança)
- **Database**: Pode ser criptografado em repouso
- **Ambos**: Use conexões seguras (TLS)

---

## ✅ Checklist

- [ ] `.env` tem `AGENT_CONVERSATION_DRIVER=hybrid`
- [ ] Redis está rodando (`redis-cli ping` retorna PONG)
- [ ] PostgreSQL/MySQL está rodando
- [ ] Migrations rodadas (`php artisan migrate`)
- [ ] Testado no tinker

---

## 🎓 Resumo

**Hybrid Storage** é perfeito quando você quer:
- ⚡ **Performance do Redis**
- 💾 **Durabilidade do Database**
- 📋 **Auditoria completa**
- 🛡️ **Fallback automático**

Pronto pra usar em produção!

---

## 🔗 Próximas leituras

- [ARCHITECTURE.md](ARCHITECTURE.md) - Diferenças Redis vs Database vs KnowledgeBase
- [SETUP.md](SETUP.md) - Configuração completa
