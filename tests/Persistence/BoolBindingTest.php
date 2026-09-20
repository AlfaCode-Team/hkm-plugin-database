<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;

/**
 * A PHP `bool` must bind the same way on every driver.
 *
 * PDO binds an unspecified parameter as a STRING, so `false` arrives as `''`.
 * SQLite's dynamic typing stores that in an INTEGER column without complaint;
 * MySQL in strict mode refuses it — `SQLSTATE[22007]: Incorrect integer value:
 * '' for column`. An application therefore passes its test suite on SQLite and
 * fails the first time it meets MySQL, which is the precise surprise this
 * adapter exists to remove.
 *
 * The tests run on SQLite, so the assertion cannot be "MySQL accepts it" — it
 * is the thing that CAUSES the MySQL failure: what lands in the column. An
 * empty string means the bug is back.
 */
#[CoversClass(MultiDriverDatabaseAdapter::class)]
final class BoolBindingTest extends TestCase
{
    private MultiDriverDatabaseAdapter $db;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->db = new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:'));
        $this->db->execute(
            'CREATE TABLE flags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, banned INTEGER NOT NULL)',
        );
    }

    public function test_false_binds_as_zero_and_not_an_empty_string(): void
    {
        $this->db->execute(
            'INSERT INTO flags (name, banned) VALUES (:name, :banned)',
            ['name' => 'alice', 'banned' => false],
        );

        $row = $this->db->queryOne('SELECT banned FROM flags WHERE name = :n', ['n' => 'alice']);

        self::assertNotNull($row);
        self::assertNotSame('', $row['banned'], "'' is what MySQL strict mode rejects");
        self::assertSame(0, (int) $row['banned']);
    }

    public function test_true_binds_as_one(): void
    {
        $this->db->execute(
            'INSERT INTO flags (name, banned) VALUES (:name, :banned)',
            ['name' => 'bob', 'banned' => true],
        );

        $row = $this->db->queryOne('SELECT banned FROM flags WHERE name = :n', ['n' => 'bob']);

        self::assertSame(1, (int) $row['banned']);
    }

    /** A bool in a WHERE clause has to match what a bool INSERT stored. */
    public function test_a_bool_round_trips_through_a_where_clause(): void
    {
        $this->db->execute('INSERT INTO flags (name, banned) VALUES (?, ?)', ['carol', false]);
        $this->db->execute('INSERT INTO flags (name, banned) VALUES (?, ?)', ['dave', true]);

        $active = $this->db->query('SELECT name FROM flags WHERE banned = :b', ['b' => false]);
        $banned = $this->db->query('SELECT name FROM flags WHERE banned = :b', ['b' => true]);

        self::assertSame(['carol'], array_column($active, 'name'));
        self::assertSame(['dave'], array_column($banned, 'name'));
    }

    /** upsert() builds its own bindings — it must not bypass the normalisation. */
    public function test_upsert_normalises_too(): void
    {
        $this->db->execute('CREATE TABLE seats (user_id TEXT PRIMARY KEY, banned INTEGER NOT NULL)');

        $this->db->upsert('seats', ['user_id' => 'u1', 'banned' => false], ['user_id']);
        self::assertSame(0, (int) $this->db->queryOne('SELECT banned FROM seats')['banned']);

        $this->db->upsert('seats', ['user_id' => 'u1', 'banned' => true], ['user_id']);
        self::assertSame(1, (int) $this->db->queryOne('SELECT banned FROM seats')['banned']);
    }

    /** Only booleans are touched; everything else is the caller's own value. */
    public function test_nothing_but_a_bool_is_altered(): void
    {
        $this->db->execute('CREATE TABLE things (a TEXT, b TEXT, c TEXT)');
        $this->db->execute(
            'INSERT INTO things (a, b, c) VALUES (:a, :b, :c)',
            ['a' => null, 'b' => '0', 'c' => 'false'],
        );

        $row = $this->db->queryOne('SELECT a, b, c FROM things');

        self::assertNull($row['a'], 'null stays null');
        self::assertSame('0', $row['b'], "a string '0' is not a bool");
        self::assertSame('false', $row['c'], "nor is the word 'false'");
    }
}
