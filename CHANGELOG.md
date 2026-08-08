# Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Não Lançado]

### Adicionado
- Setup inicial do projeto Agent Kit (toolkit de agentes de IA para Laravel com suporte multi-provider, ferramentas e RAG).
- Suporte a múltiplos providers: Anthropic, OpenAI, Gemini e DeepSeek.
- Sistema de recuperação de erros: `ErrorType`, `RecoveryContext`, `ErrorClassifier`/`DefaultErrorClassifier`, `RetryStrategy`/`AdaptiveRetryStrategy`, `Middleware`, `RetryMiddleware`, `FallbackMiddleware`, `AllProvidersFailedException` e `Pipeline` de recuperação de erros, integrados a `Agent::send()`.
- Notificação de alertas: `AlertNotifier`, `DiscordNotifier`, `NullNotifier` e `DiscordAlertMiddleware`.
- Configuração de `error_recovery` e `analytics`.
- Eventos de ciclo de vida das requisições, uso de tokens, chamadas de ferramentas e recuperação de erros, com registro condicional de listeners no service provider.
- Persistência de métricas via `PersistMetricsListener`, model `AgentMetric` e migration `agent_kit_metrics`.
- Stores de conhecimento/vetores: `ArrayStore`, `DatabaseStore`, `RedisStore`, `HybridStore` e `QdrantStore`.
- `ConversationManager`, DTOs (`AgentResponse`, `Message`, `Context`), `AbstractTool` e fachada `Agent`.
- Cobertura de testes unitários e de integração para providers, stores, eventos, middlewares e componentes core, incluindo configuração de banco SQLite para testes.

### Corrigido
- Reaplicação do clamp de delay de retry ao `max_delay_ms` após o jitter.
- Preservação da cadeia de exceções e registro da falha original do provider no `FallbackMiddleware`.
- Isolamento do `DiscordNotifier` contra falhas de entrega de webhook.
- Correção de cabeçalho removido incorretamente e bugs de teste no `QdrantStore`.
- Guarda no despacho de eventos contra falhas de listeners, aplicando `analytics.enabled` de forma consistente.
- Carregamento apenas da migration `agent_kit_metrics` nos testes, em vez do diretório completo.

### Alterado
- Limpeza da resolução e nomenclatura de providers em `Agent::send()`.
- Substituição de chamadas diretas a `Log` por eventos e listeners de log.
- Extração de helper compartilhado do Guzzle `MockHandler` para uma trait usada pelos testes de providers.

### Documentação
- Especificações de design e planos de implementação para o sistema de recuperação de erros, analytics/monitoramento e melhorias na suíte de testes unitários.
- Documentação da configuração e comportamento de recuperação de erros, e da configuração/eventos de analytics.
