<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

use InvalidArgumentException;
use RuntimeException;

/**
 * Сверяет фактическую структуру MySQL с начальной схемой модуля 0.1.0.
 */
final class MySqlSchemaInspector implements InitialSchemaVerifier
{
    private const TABLES = ['b_cmi_event', 'b_cmi_event_data', 'b_cmi_source'];

    private object $connection;

    public function __construct(object $connection)
    {
        if (!method_exists($connection, 'query')) {
            throw new InvalidArgumentException('Connection must provide query().');
        }

        $this->connection = $connection;
    }

    public function hasAnyModuleTables(): bool
    {
        $rows = $this->fetchRows(
            "SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('b_cmi_event', 'b_cmi_event_data', 'b_cmi_source')"
        );

        return $rows !== [];
    }

    public function matchesInitialSchema(): bool
    {
        if (!$this->matchesTables() || !$this->matchesColumns() || !$this->matchesIndexes()) {
            return false;
        }

        return true;
    }

    private function matchesTables(): bool
    {
        $rows = $this->fetchRows(
            "SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('b_cmi_event', 'b_cmi_event_data', 'b_cmi_source')"
        );

        if (count($rows) !== count(self::TABLES)) {
            return false;
        }

        $actual = [];
        foreach ($rows as $row) {
            $actual[$row['TABLE_NAME']] = [
                'type' => strtoupper((string) $row['TABLE_TYPE']),
                'engine' => strtolower((string) $row['ENGINE']),
                'collation' => strtolower((string) $row['TABLE_COLLATION']),
            ];
        }

        foreach (self::TABLES as $table) {
            if (($actual[$table] ?? null) !== [
                'type' => 'BASE TABLE',
                'engine' => 'innodb',
                'collation' => 'utf8mb4_unicode_ci',
            ]) {
                return false;
            }
        }

        return true;
    }

    private function matchesColumns(): bool
    {
        $rows = $this->fetchRows(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
                    CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('b_cmi_event', 'b_cmi_event_data', 'b_cmi_source')"
        );

        $expected = $this->expectedColumns();
        if (count($rows) !== array_sum(array_map('count', $expected))) {
            return false;
        }

        $actual = [];
        foreach ($rows as $row) {
            $actual[$row['TABLE_NAME']][$row['COLUMN_NAME']] = [
                'type' => strtolower((string) $row['COLUMN_TYPE']),
                'nullable' => strtoupper((string) $row['IS_NULLABLE']),
                'charset' => $row['CHARACTER_SET_NAME'] === null
                    ? null
                    : strtolower((string) $row['CHARACTER_SET_NAME']),
                'collation' => $row['COLLATION_NAME'] === null
                    ? null
                    : strtolower((string) $row['COLLATION_NAME']),
                'default' => $row['COLUMN_DEFAULT'],
                'extra' => strtolower((string) $row['EXTRA']),
            ];
        }

        foreach ($expected as $table => $columns) {
            $actualColumns = $actual[$table] ?? [];
            ksort($actualColumns);
            ksort($columns);

            if ($actualColumns !== $columns) {
                return false;
            }
        }

        return true;
    }

    private function matchesIndexes(): bool
    {
        $rows = $this->fetchRows(
            "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('b_cmi_event', 'b_cmi_event_data', 'b_cmi_source')
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
        );

        $actual = [];
        foreach ($rows as $row) {
            $key = $row['TABLE_NAME'] . ':' . $row['INDEX_NAME'];
            $actual[$key]['unique'] = (int) $row['NON_UNIQUE'] === 0;
            $actual[$key]['columns'][] = $row['COLUMN_NAME'];
        }

        $expected = [
            'b_cmi_event:PRIMARY' => ['unique' => true, 'columns' => ['EVENT_ID']],
            'b_cmi_event:IX_CMI_EVENT_DATE' => ['unique' => false, 'columns' => ['DATE_START']],
            'b_cmi_event:IX_CMI_EVENT_SOURCE_DATE' => ['unique' => false, 'columns' => ['SOURCE_CODE', 'DATE_START']],
            'b_cmi_event:IX_CMI_EVENT_STATUS_DATE' => ['unique' => false, 'columns' => ['STATUS', 'DATE_START']],
            'b_cmi_event:IX_CMI_EVENT_DIRECTION_DATE' => ['unique' => false, 'columns' => ['DIRECTION', 'DATE_START']],
            'b_cmi_event:IX_CMI_EVENT_CORRELATION' => ['unique' => false, 'columns' => ['CORRELATION_ID']],
            'b_cmi_event_data:PRIMARY' => ['unique' => true, 'columns' => ['EVENT_ID']],
            'b_cmi_source:PRIMARY' => ['unique' => true, 'columns' => ['CODE']],
        ];

        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    private function expectedColumns(): array
    {
        $text = static fn (string $type, bool $nullable = false, string $charset = 'utf8mb4', string $collation = 'utf8mb4_unicode_ci'): array => [
            'type' => $type,
            'nullable' => $nullable ? 'YES' : 'NO',
            'charset' => $charset,
            'collation' => $collation,
        ];
        $plain = static fn (string $type, bool $nullable = false): array => [
            'type' => $type,
            'nullable' => $nullable ? 'YES' : 'NO',
            'charset' => null,
            'collation' => null,
            'default' => null,
            'extra' => '',
        ];
        $id = $text('char(36)', false, 'ascii', 'ascii_bin');
        $nullableId = $text('char(36)', true, 'ascii', 'ascii_bin');

        return [
            'b_cmi_event' => [
                'EVENT_ID' => $id,
                'SOURCE_CODE' => $text('varchar(64)'),
                'OPERATION' => $text('varchar(128)'),
                'DIRECTION' => $text('varchar(12)'),
                'STATUS' => $text('varchar(12)'),
                'CORRELATION_ID' => $text('varchar(128)', true),
                'PARENT_EVENT_ID' => $nullableId,
                'DATE_START' => $plain('datetime'),
                'OCCURRED_AT' => $plain('datetime', true),
                'DATE_FINISH' => $plain('datetime', true),
                'DURATION_MS' => $plain('bigint unsigned', true),
                'HTTP_STATUS' => $plain('smallint unsigned', true),
                'RESULT_CODE' => $text('varchar(64)', true),
                'ERROR_CLASS' => $text('varchar(255)', true),
                'ERROR_CODE' => $text('varchar(64)', true),
                'ERROR_MESSAGE' => $text('varchar(1000)', true),
                'ERROR_FILE' => $text('varchar(500)', true),
                'ERROR_LINE' => $plain('int unsigned', true),
            ],
            'b_cmi_event_data' => [
                'EVENT_ID' => $id,
                'REQUEST_META' => $text('mediumtext', true),
                'RESPONSE_META' => $text('mediumtext', true),
                'CONTEXT' => $text('mediumtext', true),
                'REQUEST_PAYLOAD' => $text('mediumtext', true),
                'RESPONSE_PAYLOAD' => $text('mediumtext', true),
                'ERROR_TRACE' => $text('mediumtext', true),
            ],
            'b_cmi_source' => [
                'CODE' => $text('varchar(64)'),
                'NAME' => $text('varchar(255)'),
                'DESCRIPTION' => $text('varchar(1000)', true),
                'DATE_CREATE' => $plain('datetime'),
                'DATE_UPDATE' => $plain('datetime'),
            ],
        ];
    }

    private function fetchRows(string $sql): array
    {
        $result = $this->connection->query($sql);
        if (!is_object($result) || !method_exists($result, 'fetch')) {
            throw new RuntimeException('Unable to inspect CodeMatrix database schema.');
        }

        $rows = [];
        while (($row = $result->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('MySQL schema inspection returned an invalid row.');
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
