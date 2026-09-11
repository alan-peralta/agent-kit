<?php

namespace Peralta\AgentKit\Refactoring\Agents;

final class ClaudeCodeAgentAdapter implements AgentAdapter
{
    public function id(): string
    {
        return 'claude';
    }

    public function generate(AgentCommandRepository $repository, AgentTemplateRenderer $renderer): array
    {
        $files = [];

        foreach ($repository->names() as $name) {
            $body = $renderer->render($repository->command($name), self::CLI_VALUES);
            $frontmatter = "---\n"
                . "name: refactor-{$name}\n"
                . 'description: ' . self::DESCRIPTIONS[$name] . "\n"
                . "disable-model-invocation: true\n"
                . "---\n\n";

            $files[] = new GeneratedAgentFile(
                ".claude/skills/refactor-{$name}/SKILL.md",
                $frontmatter . $body,
            );
        }

        $files[] = new GeneratedAgentFile(
            '.claude/rules/agent-kit-refactoring.md',
            $repository->rules(),
        );

        return $files;
    }
}
