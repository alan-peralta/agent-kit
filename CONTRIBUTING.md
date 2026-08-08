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
