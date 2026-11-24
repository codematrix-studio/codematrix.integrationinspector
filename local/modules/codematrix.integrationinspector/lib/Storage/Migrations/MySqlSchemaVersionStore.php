<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

use InvalidArgumentException;
use RuntimeException;

/**
 * MySQL-хранилище версии схемы на Bitrix D7 Connection.
 */
final class MySqlSchemaVersionStore implements SchemaVersionStore
{
    private object $connection;

    public function __construct(object $connection)
    {
        if (!method_exists($connection, 'queryExecute') || !method_exists($connection, 'query')) {
            throw new InvalidArgumentException(
                'Connection must provide queryExecute() and query().'
            );
        }

        $this->connection = $connection;
    }

    public function ensureStorage(): void
    {
        $this->connection->queryExecute(
            "CREATE TABLE IF NOT EXISTS b_cmi_schema_version (
                COMPONENT VARCHAR(64) NOT NULL,
                SCHEMA_VERSION INT UNSIGNED NOT NULL,
                UPDATED_AT DATETIME NOT NULL,
                PRIMARY KEY (COMPONENT)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function getVersion(): int
    {
        $result = $this->connection->query(
            "SELECT SCHEMA_VERSION
             FROM b_cmi_schema_version
             WHERE COMPONENT = 'integrationinspector'
             LIMIT 1"
        );

        if (!is_object($result) || !method_exists($result, 'fetch')) {
            throw new RuntimeException('Unable to read CodeMatrix schema version.');
        }

        $row = $result->fetch();

        if ($row === false || $row === null) {
            return 0;
        }

        if (
            !isset($row['SCHEMA_VERSION'])
            || !preg_match('/^\\d+$/D', (string) $row['SCHEMA_VERSION'])
            || (int) $row['SCHEMA_VERSION'] > 4294967295
        ) {
            throw new RuntimeException('Stored CodeMatrix schema version is invalid.');
        }

        $version = (int) $row['SCHEMA_VERSION'];
        if ($version < 0) {
            throw new RuntimeException('Stored CodeMatrix schema version cannot be negative.');
        }

        return $version;
    }

    public function setVersion(int $version): void
    {
        if ($version < 0 || $version > 4294967295) {
            throw new InvalidArgumentException('Schema version must fit into an unsigned MySQL INT.');
        }

        $this->connection->queryExecute(
            "INSERT INTO b_cmi_schema_version (COMPONENT, SCHEMA_VERSION, UPDATED_AT)
             VALUES ('integrationinspector', " . $version . ", NOW())
             ON DUPLICATE KEY UPDATE
                SCHEMA_VERSION = VALUES(SCHEMA_VERSION),
                UPDATED_AT = VALUES(UPDATED_AT)"
        );
    }
}
