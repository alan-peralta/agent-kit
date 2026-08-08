<?php

namespace Peralta\AgentKit\Providers;

/**
 * DeepSeek é totalmente compatível com a API da OpenAI (endpoints e payload).
 * Apenas muda base_url e model. Por isso herda direto de OpenAIProvider.
 */
class DeepSeekProvider extends OpenAIProvider
{
    public function name(): string
    {
        return 'deepseek';
    }
}
