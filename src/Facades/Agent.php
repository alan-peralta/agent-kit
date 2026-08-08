<?php

namespace Peralta\AgentKit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Peralta\AgentKit\Agent provider(string $name)
 * @method static \Peralta\AgentKit\Agent model(string $model)
 * @method static \Peralta\AgentKit\Agent system(string $prompt)
 * @method static \Peralta\AgentKit\Agent tools(array $tools)
 * @method static \Peralta\AgentKit\Agent knowledgeBase(string $collection, ?string $description = null)
 * @method static \Peralta\AgentKit\Agent context(array|\Peralta\AgentKit\DTOs\Context $ctx)
 * @method static \Peralta\AgentKit\Agent conversation(string $id)
 * @method static \Peralta\AgentKit\DTOs\AgentResponse send(string $userMessage)
 *
 * @see \Peralta\AgentKit\Agent
 */
class Agent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Peralta\AgentKit\Agent::class;
    }

    /**
     * make() retorna uma nova instância (não singleton) pra evitar
     * vazamento de estado entre chamadas.
     */
    public static function make(): \Peralta\AgentKit\Agent
    {
        return app()->make(\Peralta\AgentKit\Agent::class);
    }
}
