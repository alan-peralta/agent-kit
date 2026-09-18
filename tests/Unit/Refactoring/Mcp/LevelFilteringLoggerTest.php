<?php

namespace Peralta\AgentKit\Tests\Unit\Refactoring\Mcp;

use Peralta\AgentKit\Refactoring\Mcp\LevelFilteringLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class LevelFilteringLoggerTest extends TestCase
{
    public function test_records_below_the_minimum_level_are_dropped(): void
    {
        $inner = $this->recorder();
        $logger = new LevelFilteringLogger($inner, LogLevel::WARNING);

        $logger->debug('debug');
        $logger->info('info');
        $logger->notice('notice');
        $logger->warning('warning');
        $logger->error('error');
        $logger->emergency('emergency');

        self::assertSame(['warning', 'error', 'emergency'], array_column($inner->records, 'message'));
    }

    public function test_the_level_and_context_reach_the_inner_logger_unchanged(): void
    {
        $inner = $this->recorder();
        $exception = new \RuntimeException('boom');

        (new LevelFilteringLogger($inner, LogLevel::INFO))->log(LogLevel::ERROR, 'failed', ['exception' => $exception]);

        self::assertSame([['level' => LogLevel::ERROR, 'message' => 'failed', 'context' => ['exception' => $exception]]], $inner->records);
    }

    public function test_level_names_are_case_insensitive(): void
    {
        $inner = $this->recorder();
        $logger = new LevelFilteringLogger($inner, 'ERROR');

        $logger->log('WARNING', 'dropped');
        $logger->log('Critical', 'kept');

        self::assertSame(['kept'], array_column($inner->records, 'message'));
    }

    public function test_an_unrecognised_minimum_level_drops_nothing(): void
    {
        $inner = $this->recorder();
        $logger = new LevelFilteringLogger($inner, 'verbose');

        $logger->debug('debug');
        $logger->error('error');

        self::assertSame(['debug', 'error'], array_column($inner->records, 'message'));
    }

    public function test_a_record_with_an_unrecognised_level_is_passed_on(): void
    {
        $inner = $this->recorder();

        (new LevelFilteringLogger($inner, LogLevel::EMERGENCY))->log('custom', 'passed on');

        self::assertSame(['passed on'], array_column($inner->records, 'message'));
    }

    private function recorder(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
