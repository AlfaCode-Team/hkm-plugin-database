<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Persistence;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;

/** Spy statement: records every bindValue() so the PDO type can be asserted. */
class SpyStatement extends PDOStatement
{
    /** @var list<array{0: int|string, 1: mixed, 2: int}> */
    public static array $binds = [];

    protected function __construct() {}

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        self::$binds[] = [$param, $value, $type];

        return parent::bindValue($param, $value, $type);
    }
}

/**
 * With DB_EMULATE_PREPARES the client renders values into the SQL, so an int
 * sent as a string would become LIMIT '10'. Runs on SQLite with a spy statement
 * class; the configuration only declares the emulation flag.
 */
#[CoversClass(MultiDriverDatabaseAdapter::class)]
final class TypedBindingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }
        SpyStatement::$binds = [];
    }

    private function adapter(bool $emulate): MultiDriverDatabaseAdapter
    {
        return new MultiDriverDatabaseAdapter(new class ($emulate) implements DatabaseConfigurationContract {
            public function __construct(private bool $emulate) {}
            public function driver(): string { return 'sqlite'; }
            public function dsn(): string { return 'sqlite::memory:'; }
            public function username(): ?string { return null; }
            public function password(): ?string { return null; }
            public function pdoOptions(): array
            {
                return [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => $this->emulate,
                    PDO::ATTR_STATEMENT_CLASS => [SpyStatement::class],
                ];
            }
            public function initStatements(): array { return []; }
            public function toArray(): array { return []; }
        });
    }

    public function test_emulation_binds_by_php_type(): void
    {
        $db = $this->adapter(true);
        $db->execute('CREATE TABLE t (a, b, c, d)');
        SpyStatement::$binds = [];

        $db->execute('INSERT INTO t (a, b, c, d) VALUES (?, ?, ?, ?)', [10, null, 'x', true]);

        self::assertSame([
            [1, 10, PDO::PARAM_INT],
            [2, null, PDO::PARAM_NULL],
            [3, 'x', PDO::PARAM_STR],
            [4, 1, PDO::PARAM_INT], // bool normalised to 1
        ], SpyStatement::$binds);
    }

    public function test_emulation_binds_named_params_and_limit_works(): void
    {
        $db = $this->adapter(true);
        $db->execute('CREATE TABLE t (id INTEGER)');
        $db->execute('INSERT INTO t (id) VALUES (1), (2), (3)');
        SpyStatement::$binds = [];

        $rows = $db->query('SELECT id FROM t ORDER BY id LIMIT :n OFFSET :o', ['n' => 2, 'o' => 1]);

        self::assertSame([2, 3], array_map('intval', array_column($rows, 'id')));
        self::assertSame(PDO::PARAM_INT, SpyStatement::$binds[0][2]);
        self::assertSame('n', SpyStatement::$binds[0][0]);
    }

    public function test_default_does_not_use_bindvalue(): void
    {
        $db = $this->adapter(false);
        $db->execute('CREATE TABLE t (a)');
        $db->execute('INSERT INTO t (a) VALUES (?)', [5]);

        self::assertSame([], SpyStatement::$binds);
    }
}
