<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Agents;

use InvalidArgumentException;
use Peralta\AgentKit\Refactoring\Agents\AgentTemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentTemplateRendererTest extends TestCase
{
    public function test_it_replaces_known_placeholders_without_changing_other_text(): void
    {
        $renderer = new AgentTemplateRenderer();

        self::assertSame(
            'Run php artisan audit, then php artisan impact.',
            $renderer->render(
                'Run {{cli_audit}}, then {{cli_impact}}.',
                ['cli_audit' => 'php artisan audit', 'cli_impact' => 'php artisan impact'],
            ),
        );
    }

    public function test_an_empty_values_array_leaves_plain_text_unchanged(): void
    {
        self::assertSame('Nothing to render.', (new AgentTemplateRenderer())->render('Nothing to render.', []));
    }

    public function test_it_rejects_the_first_unresolved_snake_case_placeholder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unresolved agent template placeholder: {{missing_value}}');

        (new AgentTemplateRenderer())->render('Run {{known}} then {{missing_value}}.', ['known' => 'now']);
    }

    public function test_it_rejects_an_unresolved_placeholder_even_when_its_name_is_not_snake_case(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unresolved agent template placeholder: {{MissingValue}}');

        (new AgentTemplateRenderer())->render('Run {{MissingValue}}.', []);
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_invalid_replacement_keys_or_values(array $values, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new AgentTemplateRenderer())->render('Template', $values);
    }

    public static function invalidValues(): array
    {
        return [
            'numeric key' => [['value'], 'Invalid agent template placeholder key: 0'],
            'non-snake-case key' => [['CliCommand' => 'value'], 'Invalid agent template placeholder key: CliCommand'],
            'malformed snake-case key' => [['cli__command' => 'value'], 'Invalid agent template placeholder key: cli__command'],
            'non-string value' => [['cli_command' => 42], 'Agent template value must be a string: cli_command'],
        ];
    }
}
