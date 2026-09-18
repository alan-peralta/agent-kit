# Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Não Lançado]

### Adicionado
- Suporte ao Laravel 13 (`illuminate/*` `^13.0`; o Laravel 13 exige PHP 8.3). O CI passa a rodar a suíte também no Laravel 13, com todas as dependências na versão mais nova que o `composer.json` permite.
- Suporte ao Guzzle 8 (`guzzlehttp/guzzle: ^7.0|^8.0`): um app Laravel 13 novo, que já vem com o Guzzle 8, instala o Agent Kit sem precisar de `-W`. O lock de testes continua no Guzzle 7 (Laravel 12); o CI passa a rodar a suíte também com o Guzzle 8 forçado (`Suite (Laravel 13, Guzzle 8)`).
- Snapshot em disco do índice AST (`AGENT_KIT_MCP_INDEX_CACHE_PATH`; ausente ou vazio usa `storage/framework/cache/agent-kit/index`, `false` desliga): um processo por requisição (PHP-FPM, `php artisan serve`, cada comando CLI) reaproveita o índice deixado pelo processo anterior em vez de reconstruí-lo a cada chamada. Há um arquivo por raiz de projeto analisada, descartado quando mudam os arquivos, os prefixos de facade, a versão do `nikic/php-parser` ou a revisão do Agent Kit; para limpar, apague o diretório (`php artisan cache:clear` não mexe nele). A CLI e a rota HTTP do MCP se beneficiam; o servidor stdio mantém o cache em memória entre chamadas e só lê o snapshot na primeira.
- Knowledge store `database` (`AGENT_KNOWLEDGE_STORE=database`): guarda os embeddings numa tabela comum, como base64 de float32 normalizado, e ranqueia por similaridade de cosseno em PHP. Funciona em MySQL 8 Community, MariaDB, PostgreSQL sem pgvector e SQLite, e é indicado para bases de até alguns milhares de chunks por tenant e coleção. A migration é publicada pela tag `agent-kit-database-store-migrations`. Funciona também com um `config/agent-kit.php` publicado antes desta versão (conexão padrão e tabela `knowledge_chunks`) e rejeita embeddings com valores não finitos.
- CI no GitHub Actions: suíte completa em SQLite e grupo `database` em MySQL 8.4, MariaDB 11.8 e PostgreSQL 17 com pgvector.
- Suíte de testes configurável pelas variáveis `AGENT_KIT_TEST_DB_*`, para rodar os testes do grupo `database` contra um servidor real.

### Alterado
- **BREAKING** para quem usa o transporte Streamable HTTP do servidor MCP: ele deixa de ser um processo próprio (`agent-kit:mcp --transport=http`) e passa a ser uma rota da própria aplicação Laravel, registrada quando `AGENT_KIT_MCP_HTTP_ENABLED=true`; sirva com `php artisan serve`, PHP-FPM ou Octane e aponte o cliente para `<APP_URL><AGENT_KIT_MCP_HTTP_PATH>` em vez de um host/porta próprios. `--transport=http` agora sai com uma mensagem explicando a mudança em vez de tentar escutar. `AGENT_KIT_MCP_ALLOW_REMOTE=true` muda de sentido: antes permitia fazer o bind do processo num endereço fora de loopback, agora faz a rota aceitar clientes com IP fora de loopback. `AGENT_KIT_MCP_HTTP_ENABLED=true` registra a rota em todo ambiente que lê esse `.env`; habilite-a só em desenvolvimento. Veja [MCP_SERVER.md](MCP_SERVER.md#streamable-http) e "Atualizando" no README.
- A migration do `knowledge_chunks` para pgvector saiu da tag `agent-kit-migrations` e passou para `agent-kit-pgvector-migrations`. O `php artisan migrate` deixa de exigir PostgreSQL com pgvector de quem usa MySQL, MariaDB, SQLite ou Qdrant. Instalações novas com pgvector publicam as duas tags. Quem usa MySQL, MariaDB, SQLite ou Qdrant e já publicou as migrations deve apagar do app o arquivo `2026_05_05_000002_create_knowledge_chunks_table.php`, se ele ainda não rodou; veja "Atualizando" no README.
- **BREAKING** para quem usa o servidor MCP ou os comandos de AST do Refactoring Agent: `mcp/sdk` e `nikic/php-parser` passaram de `require` para `suggest` (#11). Instalar o pacote só pelo núcleo de agentes deixa de trazer o SDK e suas dependências, entre elas o plugin do Composer `php-http/discovery`, e de impor uma versão do `nikic/php-parser`. Quem usa `agent-kit:mcp` ou `refactor-analyze`, `-callers`, `-dependencies` e `-impact` deve rodar `composer require --dev mcp/sdk nikic/php-parser` ao atualizar. Sem eles, o `agent-kit:mcp` sai com código 1 e o comando de instalação, e os comandos de AST e as tools MCP correspondentes respondem com o novo código de erro `DEPENDENCY_MISSING`; `refactor-audit` e `refactor-capabilities` continuam funcionando. O `nikic/php-parser` aceito passa a ser qualquer 5.x (antes `^5.8`), e o `mcp/sdk` continua limitado a `^0.8.1` por uma regra `conflict`.

### Removido
- `react/http`, que servia o antigo transporte HTTP do MCP como processo próprio; a versão estável mais recente não coexiste com o Guzzle 8 que o restante do pacote agora aceita.
- As opções `--host`, `--port` e `--allow-remote` de `agent-kit:mcp`, e as variáveis `AGENT_KIT_MCP_HTTP_HOST`, `AGENT_KIT_MCP_HTTP_PORT`, `AGENT_KIT_MCP_HTTP_IDLE_TIMEOUT`, `AGENT_KIT_MCP_HTTP_MAX_CONCURRENT` e `AGENT_KIT_MCP_HTTP_MAX_SESSIONS`, que só existiam para esse processo próprio; concorrência, timeouts de conexão e TLS agora são responsabilidade do servidor web que já serve a aplicação.
- Exigência da extensão `ext-fileinfo`, que só existia por causa do `mcp/sdk`; o SDK continua exigindo-a de quem o instala (#11).

### Corrigido
- Classificação de erros (`DefaultErrorClassifier`) no Guzzle 8: `RequestException::hasResponse()` não existe mais e `getResponse()` migrou para a nova `ResponseException`, e falhas de rede sem resposta agora chegam como subclasses de `NetworkException`, não de `RequestException`. O classificador passa a reconhecer as duas versões do Guzzle sem referenciar nenhuma classe exclusiva de uma delas; sem a correção, uma resposta 4xx/5xx no Guzzle 8 lançaria `Error` em vez de classificar o erro.
- `QdrantStore` no Guzzle 8: o truque de zerar `Authorization` e `api-key` com um array vazio (`'Authorization' => []`) para descartar os cabeçalhos herdados do client injetado deixou de funcionar, porque o Guzzle 8 rejeita valores de cabeçalho em array vazio. A remoção agora usa `'headers' => null`, que descarta os cabeçalhos do client no Guzzle 7 e 8 igualmente.

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
