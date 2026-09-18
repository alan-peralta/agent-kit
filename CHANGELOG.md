# Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Não Lançado]

## [0.3.0] - 2026-09-18

### Adicionado
- Opções por chamada `response_format` e `timeout` em `OpenAIProvider`, herdadas sem override por `DeepSeekProvider` e configuráveis via `Agent::options()`. `response_format` é repassada como recebida no payload de `POST chat/completions` (use `['type' => 'json_object']` para obter JSON parseável do provider em vez de depender do prompt); `timeout` é opção Guzzle da requisição, em segundos (`int|float`), que sobrescreve `agent-kit.providers.*.timeout` (default 60 s) apenas naquela chamada e nunca entra no corpo JSON. Ambas são opcionais e retrocompatíveis: quando não informadas, o payload e as opções HTTP da requisição permanecem byte a byte idênticos aos da v0.2.0, o que é coberto por testes-guarda. `AnthropicProvider` e `GeminiProvider` não consomem nenhuma das duas.

### Documentação
- `README.md` ganha a seção "Opções por chamada", descrevendo `response_format` e `timeout` nos providers compatíveis com a API da OpenAI (`openai` e `deepseek`), com exemplo de uso e o comportamento padrão na ausência de cada opção.

## [0.2.0] - 2026-09-18

_Atualizando da v0.1.0: veja a seção "Atualizando" do [README](README.md#atualizando)._

### Adicionado
- Refactoring Agent: análise estrutural determinística de projetos Laravel pelos comandos `agent-kit:refactor-capabilities`, `agent-kit:refactor-audit`, `agent-kit:refactor-analyze`, `agent-kit:refactor-callers`, `agent-kit:refactor-dependencies` e `agent-kit:refactor-impact`, com saída humana e JSON (`--json`) em envelope estável (`schema_version`, `capability`, `incomplete`, `data`, `diagnostics`, `unresolved`) e erros `{schema_version, error: {code, message}}`.
- Índice AST com `nikic/php-parser`: símbolos (classes, interfaces, traits, enums, métodos, propriedades, constantes, atributos), grafo de dependências tipado com confiança (`exact`/`inferred`/`unknown`), análise de callers e de impacto (dependentes diretos, estruturais e transitivos, arquivos afetados e risco por limiares configuráveis), evidência explícita de referências não resolvidas e reconhecimento de padrões Laravel (`event()`, `dispatch()`, `Bus`/`Event`, Facades, `app()`/`resolve()`/`app()->make()`).
- Contrato `RefactoringCapabilities` compartilhado entre CLI, agentes de código e MCP; configuração `agent-kit.refactoring` (`exclude`, `thresholds`, `impact_thresholds`, `facades`).
- Integração com agentes de código: `agent-kit:agents:install` gera skills e regras nativas para Cursor e Claude Code (`/refactor-audit`, `/refactor-analyze`, `/refactor-callers`, `/refactor-dependencies`, `/refactor-impact`, `/refactor-plan`) a partir de templates compartilhados, com instalação atômica, relatório de conflitos, `--force` explícito, contenção de caminho e proteção contra symlinks; nenhum comando de aplicação automática (`/refactor-apply`) é gerado.
- Servidor MCP para o Refactoring Agent (`php artisan agent-kit:mcp`): seis tools somente-leitura (`refactoring_capabilities`, `refactoring_audit`, `refactoring_analyze`, `refactoring_callers`, `refactoring_dependencies`, `refactoring_impact`), resource `agent-kit://refactoring/capabilities`, transporte stdio e Streamable HTTP (opt-in, bind em loopback, bearer token, allowlist de origins, limites de corpo/concorrência/sessões), cache do índice AST por fingerprint de conteúdo e configuração `agent-kit.mcp`.
- Dependência `mcp/sdk ^0.8.1`; `react/http` sugerido para o transporte HTTP.
- Indexação de código procedural no Refactoring Agent: arquivos com código fora de classes (`routes/*.php`, `config/*.php`, `bootstrap/app.php`, helpers, migrations com classe anônima) geram um símbolo `script` identificado pelo caminho relativo à raiz; funções top-level são registradas como rotinas do script (`helpers.php::make_user` vira alvo de `refactor-analyze`) e caminhos de script são aceitos como alvo em `refactor-dependencies`, `refactor-callers` e `refactor-impact`.

### Corrigido
- `StructureCollector` não quebra mais em código PHP fora de classes nomeadas (closures, `if`, ternários, `??`, `match` no topo do arquivo, funções globais e corpos de classes anônimas): `leaveNode()` passa a espelhar a guarda de `enterNode()`, evitando o esvaziamento das pilhas de escopo que fazia `refactor-analyze`/`callers`/`dependencies`/`impact` falharem em qualquer app Laravel real (`routes/*.php`, `bootstrap/app.php`, migrations).
- `CodebaseIndexer` converte falhas de leitura ou de análise de um único arquivo em diagnóstico (`Analysis failed: …`) em vez de abortar o índice inteiro.
- Funções nomeadas declaradas dentro de métodos ganham escopo local próprio e deixam de sobrescrever os tipos locais do método que as declara.
- `agent-kit.logging.log_tool_calls` e `log_messages` passam a ler `AGENT_LOG_TOOL_CALLS` e `AGENT_LOG_MESSAGES`, como o `.env.example` já anunciava.
- `cli_fallback` de `find_callers` e `impact` em `describeCapabilities()`/`refactoring_capabilities` passa a usar a forma `"<class>[::<method>]"` em vez da opção `--method=` (depreciada).

### Alterado
- `GeminiProvider` e `GeminiEmbedder` passam a enviar a chave de API no header `x-goog-api-key` em vez da query string `?key=`, evitando vazamento da credencial em logs de acesso, proxies e históricos de URL.
- `describeCapabilities()` passa a informar `mcp_tool` em cada descritor; `agent-kit:refactor-capabilities` exibe a coluna MCP tool.
- Skills de Cursor/Claude Code passam a nomear as tools MCP reais antes do fallback de CLI.
- `ClassName::class` passa a gerar aresta `class_constant` (`metadata.constant = "class"`) e corpos de classes anônimas passam a contribuir referências atribuídas à rotina que os declara; como scripts agora contam como dependentes em `refactor-impact`/`refactor-callers`, o risco de classes referenciadas por rotas, config e migrations pode subir. `self::class`/`static::class` dentro da própria classe geram auto-arestas `class_constant` (mesma categoria de `self::CONST` e `$this->m()`), o que pode contar a classe como um dependente estrutural de si mesma. Alvos de função (`helpers.php::make_user`) respondem com `risk: UNKNOWN` e um diagnóstico, porque chamadas a funções não são indexadas; atributos de funções top-level geram arestas `attribute`.

### Documentação
- `REFACTORING_AGENT.md` (arquitetura, comandos, esquema JSON, confiança, código procedural, limites) e `MCP_SERVER.md` (transportes, configuração de Claude Code/Cursor/Codex, segurança, troubleshooting); especificações e planos das iterações do Refactoring Agent e do servidor MCP em `docs/superpowers/`.

## [0.1.0] - 2026-09-01

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

[Não Lançado]: https://github.com/alan-peralta/agent-kit/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/alan-peralta/agent-kit/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/alan-peralta/agent-kit/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/alan-peralta/agent-kit/releases/tag/v0.1.0
