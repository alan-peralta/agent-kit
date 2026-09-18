<?php

namespace Peralta\AgentKit\Refactoring\Mcp;

use Composer\InstalledVersions;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use OutOfBoundsException;
use Peralta\AgentKit\Refactoring\Application\RefactoringCapabilities;
use Psr\Log\LoggerInterface;

final class McpServerFactory
{
    public const SERVER_NAME = 'agent-kit-refactoring';

    private const INSTRUCTIONS = <<<'TEXT'
Agent Kit deterministic refactoring analysis for PHP/Laravel. ANALYZE != MODIFY: every tool is read-only and operates on the project root fixed when this server started; there is no apply, edit or shell tool.
Use refactoring_capabilities (or the agent-kit://refactoring/capabilities resource) to discover tools. Targets are project-relative PHP files (refactoring_analyze), fully qualified class names, script paths such as routes/web.php wherever a class is accepted, or Class::method / file.php::function where method scope is supported; function targets return risk UNKNOWN with a diagnostic because calls to user-defined functions are not indexed.
Results carry structuredContent with schema_version, capability, incomplete, data, diagnostics and unresolved. incomplete=true means static analysis could not resolve everything: read diagnostics (parse problems) and unresolved (dynamic references) instead of guessing. Domain failures come back as tool errors (isError=true) with {schema_version, error: {code, message}}.
Dependency never proves breakage; report impact as "potentially affected" and verify with tests.
TEXT;

    public function __construct(
        private readonly RefactoringCapabilities $capabilities,
        private readonly RefactoringToolCatalog $catalog,
    ) {}

    public function create(
        McpProjectRoot $root,
        LoggerInterface $logger,
        SessionStoreInterface $sessions,
        int $gcProbability = 1,
    ): Server {
        $builder = Server::builder()
            ->setServerInfo(self::SERVER_NAME, self::version(), 'Deterministic PHP/Laravel refactoring analysis (read-only).')
            ->setInstructions(self::INSTRUCTIONS)
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                toolsListChanged: false,
                resources: true,
                resourcesSubscribe: false,
                resourcesListChanged: false,
                prompts: false,
                promptsListChanged: false,
                logging: false,
                completions: false,
            ))
            ->setLogger($logger)
            ->setLazyLoading(false)
            ->setSession($sessions, gcProbability: $gcProbability);

        foreach ($this->catalog->tools() as $definition) {
            $builder->add($definition->toTool(), new RefactoringToolHandler($this->capabilities, $root, $definition));
        }

        $builder->add($this->catalog->resourceDefinition(), new CapabilitiesResourceHandler(
            $this->capabilities,
            $this->catalog,
            $root,
            self::SERVER_NAME,
            self::version(),
        ));

        return $builder->build();
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('peralta/agent-kit') ?? 'dev';
        } catch (OutOfBoundsException) {
            return 'dev';
        }
    }
}
