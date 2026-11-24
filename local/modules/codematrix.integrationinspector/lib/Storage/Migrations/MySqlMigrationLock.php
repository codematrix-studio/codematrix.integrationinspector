<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

use InvalidArgumentException;
use RuntimeException;

/**
 * MySQL advisory lock for a single module schema migration process.
 */
final class MySqlMigrationLock implements MigrationLock
{
    private object $connection;
    private string $lockName;
    private int $timeoutSeconds;
    private bool $acquired = false;

    public function __construct(
        object $connection,
        string $lockName = 'cmi_integrationinspector_schema',
        int $timeoutSeconds = 10
    ) {
        if (!method_exists($connection, 'query')) {
            throw new InvalidArgumentException('Connection must provide query().');
        }

        if ($lockName === '' || strlen($lockName) > 64) {
            throw new InvalidArgumentException('MySQL advisory lock name must contain 1 to 64 bytes.');
        }

        if ($timeoutSeconds < 0) {
            throw new InvalidArgumentException('Lock timeout cannot be negative.');
        }

        $this->connection = $connection;
        $this->lockName = $lockName;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function acquire(): bool
    {
        if ($this->acquired) {
            return true;
        }

        $name = str_replace("'", "''", $this->lockName);
        $result = $this->connection->query(
            "SELECT GET_LOCK('" . $name . "', " . $this->timeoutSeconds . ') AS LOCK_RESULT'
        );

        $row = $this->fetchRow($result);
        if (!array_key_exists('LOCK_RESULT', $row) || $row['LOCK_RESULT'] === null) {
            throw new RuntimeException('MySQL did not return a result for GET_LOCK().');
        }

        $acquired = (int) $row['LOCK_RESULT'] === 1;
        $this->acquired = $acquired;

        return $acquired;
    }

    public function release(): void
    {
        if (!$this->acquired) {
            return;
        }

        // Сбрасываем локальное состояние до обращения к БД: при потере
        // соединения или чужом RELEASE_LOCK() следующий acquire обязан
        // запросить блокировку заново, а не считать её уже полученной.
        $this->acquired = false;

        $name = str_replace("'", "''", $this->lockName);
        $result = $this->connection->query(
            "SELECT RELEASE_LOCK('" . $name . "') AS LOCK_RESULT"
        );

        $row = $this->fetchRow($result);
        if (!array_key_exists('LOCK_RESULT', $row) || (int) $row['LOCK_RESULT'] !== 1) {
            throw new RuntimeException('Unable to release MySQL schema migration lock.');
        }

    }

    private function fetchRow(mixed $result): array
    {
        if (!is_object($result) || !method_exists($result, 'fetch')) {
            throw new RuntimeException('Unable to read MySQL advisory lock result.');
        }

        $row = $result->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('MySQL advisory lock returned an invalid result.');
        }

        return $row;
    }
}
