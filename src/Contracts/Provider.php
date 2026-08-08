<?php

namespace Peralta\AgentKit\Contracts;

use Peralta\AgentKit\DTOs\AgentResponse;

interface Provider
{
    /**
     * Envia mensagens ao modelo e recebe resposta normalizada.
     *
     * @param  array  $messages  Array de Message DTOs
     * @param  array  $tools     Array de Tool instances
     * @param  string|null  $system  System prompt
     * @param  array  $options  Opções específicas (model, max_tokens, temperature, etc.)
     */
    public function chat(
        array $messages,
        array $tools = [],
        ?string $system = null,
        array $options = []
    ): AgentResponse;

    /**
     * Nome curto do provider (openai, anthropic, gemini, deepseek).
     */
    public function name(): string;
}
