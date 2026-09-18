<?php

namespace Peralta\AgentKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The agent core must not drag the MCP server's or the AST index's packages into apps (#11). */
final class ComposerManifestTest extends TestCase
{
    private const OPTIONAL = ['mcp/sdk', 'nikic/php-parser'];

    public function test_the_mcp_and_ast_packages_are_only_suggested(): void
    {
        $manifest = $this->json('composer.json');

        foreach ([...self::OPTIONAL, 'ext-fileinfo'] as $package) {
            self::assertArrayNotHasKey($package, $manifest['require'], "{$package} must not be required.");
        }
        foreach (self::OPTIONAL as $package) {
            self::assertArrayHasKey($package, $manifest['require-dev'], "{$package} is still needed by the package's own tests.");
            self::assertArrayHasKey($package, $manifest['suggest'], "{$package} must be suggested.");
        }
        self::assertSame('<0.8.1 || >=0.9', $manifest['conflict']['mcp/sdk'] ?? null);
        self::assertArrayNotHasKey('nikic/php-parser', $manifest['conflict'], 'An old php-parser elsewhere must not block the agent core.');
    }

    public function test_a_production_install_gets_none_of_them(): void
    {
        $lock = $this->json('composer.lock');
        $production = array_column($lock['packages'], 'name');

        foreach ([
            'mcp/sdk',
            'nikic/php-parser',
            'opis/json-schema',
            'opis/string',
            'opis/uri',
            'php-http/discovery',
            'psr/http-server-handler',
            'psr/http-server-middleware',
        ] as $package) {
            self::assertNotContains($package, $production, "{$package} would be installed with --no-dev.");
        }
        self::assertArrayNotHasKey('ext-fileinfo', $lock['platform']);
    }

    private function json(string $file): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file), true, flags: JSON_THROW_ON_ERROR);
    }
}
