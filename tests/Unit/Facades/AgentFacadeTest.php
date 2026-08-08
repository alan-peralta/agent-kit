<?php

namespace Peralta\AgentKit\Tests\Unit\Facades;

use Peralta\AgentKit\Agent;
use Peralta\AgentKit\Facades\Agent as AgentFacade;
use Peralta\AgentKit\Tests\TestCase;

class AgentFacadeTest extends TestCase
{
    public function test_facade_resolves_to_agent_container_binding()
    {
        $this->assertInstanceOf(Agent::class, AgentFacade::getFacadeRoot());
    }

    public function test_make_returns_a_new_agent_instance_each_time()
    {
        $first = AgentFacade::make();
        $second = AgentFacade::make();

        $this->assertInstanceOf(Agent::class, $first);
        $this->assertInstanceOf(Agent::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function test_facade_fluent_call_returns_agent_instance()
    {
        $result = AgentFacade::provider('openai');

        $this->assertInstanceOf(Agent::class, $result);
    }
}
