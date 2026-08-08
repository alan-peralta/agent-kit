<?php

namespace Peralta\AgentKit\ErrorRecovery;

use Peralta\AgentKit\DTOs\RecoveryContext;
use Peralta\AgentKit\ErrorRecovery\Contracts\Middleware;

class Pipeline
{
    /**
     * @param  Middleware[]  $middleware  Ordenados do mais externo pro mais interno.
     */
    public function __construct(
        protected array $middleware,
    ) {}

    public function execute(callable $handler, RecoveryContext $context): mixed
    {
        $chain = array_reduce(
            array_reverse($this->middleware),
            fn(callable $next, Middleware $mw) => fn(RecoveryContext $ctx) => $mw->handle($next, $ctx),
            $handler,
        );

        return $chain($context);
    }
}
