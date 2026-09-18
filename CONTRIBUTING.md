# Contribuindo com o Agent Kit

Obrigado por considerar contribuir com o Agent Kit! Este documento explica como configurar o ambiente, os padrões do projeto e o fluxo para enviar mudanças.

## Filosofia do projeto

- **Pacote = infraestrutura.** Loop de execução, providers, persistência, RAG genérico.
- **Projeto = domínio.** Tools concretas, regras de negócio e integrações específicas ficam fora do pacote.

Ao propor mudanças, avalie se o comportamento pertence à infraestrutura (pacote) ou ao domínio de uma aplicação (não deve entrar aqui).

## Requisitos

- PHP ^8.2
- Composer
- Laravel 10, 11 ou 12 (via `orchestra/testbench` para os testes)
- Extensão `pdo_sqlite` habilitada (usada na suíte de testes)
- Opcional: `pdo_mysql` e `pdo_pgsql`, para rodar os testes de banco contra MySQL, MariaDB ou PostgreSQL

## Configurando o ambiente

```bash
git clone <url-do-repositorio>
cd agent-kit
composer install
```

## Rodando os testes

```bash
vendor/bin/phpunit
```

A suíte é dividida em `tests/Unit`, `tests/Feature` e `tests/Integration`. Todo PR deve manter a suíte passando.

Para relatório de cobertura:

```bash
vendor/bin/phpunit --coverage-text
```

### Contra MySQL, MariaDB ou PostgreSQL

Por padrão a suíte usa SQLite em memória. Os testes que tocam o banco pertencem ao
grupo `database` e também rodam contra um servidor real, escolhido por variáveis de
ambiente:

| Variável | Padrão |
|---|---|
| `AGENT_KIT_TEST_DB_CONNECTION` | `sqlite` (ou `mysql`, `mariadb`, `pgsql`) |
| `AGENT_KIT_TEST_DB_HOST` | `127.0.0.1` |
| `AGENT_KIT_TEST_DB_PORT` | `3306` ou `5432`, conforme o driver |
| `AGENT_KIT_TEST_DB_DATABASE` | `agent_kit_test` |
| `AGENT_KIT_TEST_DB_USERNAME` | `root` (MySQL e MariaDB) ou `postgres` |
| `AGENT_KIT_TEST_DB_PASSWORD` | vazio |

Exemplo com Docker:

```bash
docker run -d --rm --name agent-kit-mysql -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=agent_kit_test -p 33061:3306 mysql:8.4
docker run -d --rm --name agent-kit-mariadb -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=agent_kit_test -p 33062:3306 mariadb:11.8
docker run -d --rm --name agent-kit-pgsql -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=agent_kit_test -p 54329:5432 pgvector/pgvector:pg17

AGENT_KIT_TEST_DB_CONNECTION=mysql AGENT_KIT_TEST_DB_PORT=33061 AGENT_KIT_TEST_DB_PASSWORD=secret vendor/bin/phpunit --group database
```

Com um servidor configurado, o `TestCase` apaga todas as tabelas do banco ao fim de
cada teste. Use um banco dedicado aos testes, nunca o da sua aplicação. O driver
`mariadb` exige Laravel 11 ou superior.

## Padrões de código

- PSR-4 (`Peralta\AgentKit\` → `src/`, `Peralta\AgentKit\Tests\` → `tests/`).
- Tipagem estrita: use type hints e retornos tipados em todo código novo.
- Sem comentários explicando o óbvio — comente apenas decisões não óbvias (workarounds, invariantes, limitações de API externa).
- Não adicione abstrações, flags ou tratamento de erro para cenários que não podem ocorrer. Prefira código direto e específico ao problema.
- Contracts (interfaces) ficam junto da implementação padrão quando fizer sentido (ex.: `ErrorClassifier` + `DefaultErrorClassifier`, `RetryStrategy` + `AdaptiveRetryStrategy`).

## Testes

- Toda feature nova ou correção de bug deve vir acompanhada de testes.
- Prefira testar comportamento real (HTTP mockado via Guzzle `MockHandler`, banco SQLite em memória) em vez de mocks excessivos que escondem regressões.
- Use os traits e helpers já existentes em `tests/` (ex.: helper compartilhado de `MockHandler` para providers) antes de duplicar setup.
- Marque com `#[Group('database')]` todo teste que toca o banco, para que ele rode também contra MySQL, MariaDB e PostgreSQL.

## Documentação

- Novas configurações (`config/agent-kit.php`) devem ser documentadas no `README.md`.
- Mudanças arquiteturais relevantes devem ser refletidas em `ARCHITECTURE.md`.
- Toda mudança notável deve ser registrada em `CHANGELOG.md`, na seção `[Não Lançado]`, seguindo o formato [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).

## Commits

Use mensagens de commit no formato `tipo: descrição`, com o tipo indicando a natureza da mudança:

- `feat`: nova funcionalidade
- `fix`: correção de bug
- `refactor`: mudança interna sem alterar comportamento
- `test`: adição ou ajuste de testes
- `docs`: documentação
- `chore`: manutenção (configuração, dependências, etc.)

Exemplo: `feat: add FallbackMiddleware and AllProvidersFailedException`

## Enviando mudanças

1. Crie uma branch a partir de `main` com um nome descritivo.
2. Faça commits pequenos e coesos.
3. Garanta que `vendor/bin/phpunit` passa sem falhas.
4. Atualize `CHANGELOG.md` e demais documentos relevantes.
5. Abra um Pull Request descrevendo o problema resolvido e a abordagem escolhida.

## Reportando bugs e sugerindo features

Abra uma issue descrevendo:

- Comportamento esperado vs. observado (para bugs), com passos para reproduzir.
- Caso de uso e motivação (para features), preferencialmente indicando se pertence ao pacote (infraestrutura) ou ao projeto consumidor (domínio).
