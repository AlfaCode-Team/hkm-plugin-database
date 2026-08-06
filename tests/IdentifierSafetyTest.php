<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\MySQLConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;

/**
 * Regression cover for S-32d: table and column identifiers are interpolated
 * into SQL (they cannot be bound), and were only "sanitised" by stripping quote
 * characters — which let every other character through.
 */
#[CoversClass(MultiDriverDatabaseAdapter::class)]
final class IdentifierSafetyTest extends TestCase
{
    private function quote(string $identifier): string
    {
        $adapter = new MultiDriverDatabaseAdapter(new MySQLConfiguration(host: 'h', database: 'd'));
        $m       = new \ReflectionMethod($adapter, 'quoteId');

        return (string) $m->invoke($adapter, $identifier);
    }

    /** @return list<array{string}> */
    public static function hostileIdentifiers(): array
    {
        return [
            ['users; DROP TABLE users'],
            ['users WHERE 1=1 --'],
            ['users`, (SELECT 1)'],
            ["users\nunion select"],
            ['users users'],
            [''],
            ['*'],
        ];
    }

    #[DataProvider('hostileIdentifiers')]
    public function test_an_unsafe_identifier_is_refused(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsafe SQL identifier/');

        $this->quote($identifier);
    }

    public function test_a_plain_identifier_is_quoted(): void
    {
        self::assertSame('`users`', $this->quote('users'));
        self::assertSame('`user_id`', $this->quote('user_id'));
        self::assertSame('`col2`', $this->quote('col2'));
    }

    public function test_a_qualified_identifier_quotes_each_part(): void
    {
        // `schema.table` as one token would be an invalid identifier.
        self::assertSame('`app`.`users`', $this->quote('app.users'));
    }

    public function test_two_dots_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->quote('a.b.c');
    }
}
