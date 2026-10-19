<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Drivers;

use PDO;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;

/**
 * MySQLConfiguration — MySQL/MariaDB database configuration.
 * Supports both Unix socket and TCP connections.
 */
final readonly class MySQLConfiguration implements DatabaseConfigurationContract
{
    public function __construct(
        private string $host = 'localhost',
        private int $port = 3306,
        private string $database = '',
        private string $username = 'root',
        private string $password = '',
        private string $charset = 'utf8mb4',
        private bool $useSslVerify = false,
        private ?string $sslCa = null,
        private ?string $unixSocket = null,
    ) {}

    public function driver(): string
    {
        return 'mysql';
    }

    public function dsn(): string
    {
        if ($this->unixSocket) {
            return "mysql:unix_socket={$this->unixSocket};dbname={$this->database};charset={$this->charset}";
        }

        return "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";
    }

    public function username(): ?string
    {
        return $this->username ?: null;
    }

    public function password(): ?string
    {
        return $this->password ?: null;
    }

    public function pdoOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // The WHOLE session setup, in ONE statement, here rather than in
            // initStatements(): PDO runs INIT_COMMAND on every connect AND every
            // auto-reconnect, so a dropped connection comes back UTC and strict
            // too (initStatements() only run on the first connect). One round
            // trip per connection instead of three — every request that touches
            // the database opens one.
            //
            //   NAMES     the connection charset (also in the DSN; kept so
            //             character_set_results follows it on every server)
            //   time_zone '+00:00' — CURRENT_TIMESTAMP/NOW() and TIMESTAMP
            //             read-back are UTC; a numeric offset needs no tz tables
            //   sql_mode  strict, so silent truncation/coercion never reaches
            //             production
            //
            // The driver-specific constants live on \Pdo\Mysql (PHP 8.4+), NOT on
            // PDO: the PDO::MYSQL_* aliases are deprecated as of 8.5 and emit a
            // notice on every connection. Written fully qualified because an
            // unqualified `Pdo\Mysql` would resolve inside THIS namespace, and
            // `use Pdo\Mysql;` would leave a bare `Mysql::` at the call site.
            \Pdo\Mysql::ATTR_INIT_COMMAND => self::sessionSetup($this->charset),
        ];

        if ($this->sslCa && $this->useSslVerify) {
            $options[\Pdo\Mysql::ATTR_SSL_CA] = $this->sslCa;
            $options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        return $options;
    }

    public function initStatements(): array
    {
        // Nothing: charset, UTC and strict mode are all set by the single
        // INIT_COMMAND in pdoOptions(). Statements here would cost a round
        // trip each on every connection.
        return [];
    }

    /** The one statement every connection starts with. */
    public static function sessionSetup(string $charset = 'utf8mb4'): string
    {
        return "SET NAMES {$charset}, time_zone = '+00:00', "
            . "SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'";
    }

    public function toArray(): array
    {
        return [
            'driver' => $this->driver(),
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'charset' => $this->charset,
            'unix_socket' => $this->unixSocket,
        ];
    }
}
