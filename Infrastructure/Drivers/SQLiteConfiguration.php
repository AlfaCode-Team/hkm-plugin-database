<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Drivers;

use PDO;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;

/**
 * SQLiteConfiguration — SQLite database configuration.
 * Supports file-based and in-memory databases.
 */
final readonly class SQLiteConfiguration implements DatabaseConfigurationContract
{
    /**
     * @param string   $path  ':memory:' or a file path
     * @param int|null $flags PDO SQLite open flags. null = pdo_sqlite's own
     *                        READWRITE|CREATE default, resolved lazily.
     *
     * NOTE: the flags default is deliberately null rather than a constant
     * expression. It used to be `SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE`,
     * which was wrong twice over:
     *
     *   1. SQLITE3_* comes from ext-sqlite3 (the procedural SQLite3 class),
     *      while PDO::SQLITE_ATTR_OPEN_FLAGS expects pdo_sqlite's
     *      PDO::SQLITE_OPEN_* family. The numeric values coincide, so it
     *      "worked" — on hosts that happened to load an extension this driver
     *      does not otherwise use.
     *   2. A constant in a default parameter value is evaluated when the class
     *      is loaded, so merely REFERENCING SQLiteConfiguration fataled with
     *      "Undefined constant SQLITE3_OPEN_READWRITE" wherever ext-sqlite3 was
     *      absent — including in tests that never open a connection.
     */
    public function __construct(
        private string $path = ':memory:',
        private ?int $flags = null,
    ) {}

    public function driver(): string
    {
        return 'sqlite';
    }

    public function dsn(): string
    {
        return "sqlite:{$this->path}";
    }

    public function username(): ?string
    {
        return null;
    }

    public function password(): ?string
    {
        return null;
    }

    public function pdoOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Both the attribute and the flag constants come from pdo_sqlite. Guard
        // on them so this class stays loadable (and testable) without the
        // extension; a connection would fail later anyway, with a clear PDO
        // error naming the missing driver.
        if (\defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) {
            $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = $this->flags
                ?? (PDO::SQLITE_OPEN_READWRITE | PDO::SQLITE_OPEN_CREATE);
        }

        return $options;
    }

    public function initStatements(): array
    {
        // SQLite disables foreign-key enforcement by default — enforce it, and
        // set a busy timeout so concurrent writers wait rather than fail instantly.
        $statements = [
            'PRAGMA foreign_keys = ON',
            'PRAGMA busy_timeout = 5000',
        ];

        // WAL improves read/write concurrency but is meaningless for :memory:.
        if ($this->path !== ':memory:') {
            $statements[] = 'PRAGMA journal_mode = WAL';
        }

        return $statements;
    }

    public function toArray(): array
    {
        return [
            'driver' => $this->driver(),
            'path' => $this->path,
            'in_memory' => $this->path === ':memory:',
        ];
    }
}
