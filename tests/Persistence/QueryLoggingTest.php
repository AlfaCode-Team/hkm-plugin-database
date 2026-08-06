<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;

#[CoversClass(MultiDriverDatabaseAdapter::class)]
final class QueryLoggingTest extends TestCase
{

    protected function setUp(): void
    {
        // These exercise a REAL sqlite connection. Skipping keeps the suite
        // honest on a host without the driver instead of reporting 30+ errors
        // that say nothing about the code under test. CI installs pdo_sqlite,
        // so this genuinely runs there.
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }
    }
    public function test_no_logging_when_logger_absent(): void
    {
        $db = new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:'));

        // Simply must not error without a logger.
        $db->query('SELECT 1');
        $this->assertTrue(true);
    }

    public function test_debug_logging_when_enabled(): void
    {
        $logger = $this->spyLogger();
        $db = new MultiDriverDatabaseAdapter(
            config: new SQLiteConfiguration(':memory:'),
            logger: $logger,
            logQueries: true,
        );

        $db->query('SELECT 1');

        $debugEntries = array_filter($logger->records, static fn ($r) => $r['level'] === 'debug');
        $this->assertNotEmpty($debugEntries);
        $entry = array_values($debugEntries)[0];
        $this->assertSame('sqlite', $entry['context']['driver']);
        $this->assertArrayHasKey('elapsed_ms', $entry['context']);
    }

    public function test_no_debug_logging_when_disabled(): void
    {
        $logger = $this->spyLogger();
        $db = new MultiDriverDatabaseAdapter(
            config: new SQLiteConfiguration(':memory:'),
            logger: $logger,
            logQueries: false,
        );

        $db->query('SELECT 1');

        $debugEntries = array_filter($logger->records, static fn ($r) => $r['level'] === 'debug');
        $this->assertEmpty($debugEntries);
    }

    public function test_slow_query_logged_as_warning_regardless_of_flag(): void
    {
        $logger = $this->spyLogger();
        // Threshold of 0ms guarantees every query counts as "slow".
        $db = new MultiDriverDatabaseAdapter(
            config: new SQLiteConfiguration(':memory:'),
            logger: $logger,
            logQueries: false,
            slowQueryThresholdMs: 0.0,
        );

        $db->query('SELECT 1');

        $warnings = array_filter($logger->records, static fn ($r) => $r['level'] === 'warning');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Slow database query', array_values($warnings)[0]['message']);
    }

    /**
     * The adapter takes the kernel's LoggerPort, not Psr\Log — the kernel
     * defines its own logging contract so plugins are not coupled to a vendor
     * interface. This spy therefore implements LoggerPort directly.
     */
    private function spyLogger(): LoggerPort
    {
        return new class implements LoggerPort {
            /** @var list<array{level: string, message: string, context: array}> */
            public array $records = [];

            public function emergency(string|\Stringable $m, array $c = []): void { $this->log('emergency', $m, $c); }
            public function alert(string|\Stringable $m, array $c = []): void     { $this->log('alert', $m, $c); }
            public function critical(string|\Stringable $m, array $c = []): void  { $this->log('critical', $m, $c); }
            public function error(string|\Stringable $m, array $c = []): void     { $this->log('error', $m, $c); }
            public function warning(string|\Stringable $m, array $c = []): void   { $this->log('warning', $m, $c); }
            public function notice(string|\Stringable $m, array $c = []): void    { $this->log('notice', $m, $c); }
            public function info(string|\Stringable $m, array $c = []): void      { $this->log('info', $m, $c); }
            public function debug(string|\Stringable $m, array $c = []): void     { $this->log('debug', $m, $c); }

            public function log(string $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level'   => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
