<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Storage\Migrations;

use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlSchemaInspector;
use PHPUnit\Framework\TestCase;

final class MySqlSchemaInspectorTest extends TestCase
{
    public function testRecognizesExactInitialSchema(): void
    {
        $inspector = new MySqlSchemaInspector(new FakeSchemaInspectorConnection(self::schemaResultSets()));

        self::assertTrue($inspector->matchesInitialSchema());
    }

    public function testRejectsMissingIndex(): void
    {
        $sets = self::schemaResultSets();
        array_pop($sets[2]);
        $inspector = new MySqlSchemaInspector(new FakeSchemaInspectorConnection($sets));

        self::assertFalse($inspector->matchesInitialSchema());
    }

    public function testRejectsNonBtreeIndex(): void
    {
        $sets = self::schemaResultSets();
        $sets[2][1]['INDEX_TYPE'] = 'FULLTEXT';
        $inspector = new MySqlSchemaInspector(new FakeSchemaInspectorConnection($sets));

        self::assertFalse($inspector->matchesInitialSchema());
    }

    public function testRejectsPrefixIndex(): void
    {
        $sets = self::schemaResultSets();
        $sets[2][1]['SUB_PART'] = 12;
        $inspector = new MySqlSchemaInspector(new FakeSchemaInspectorConnection($sets));

        self::assertFalse($inspector->matchesInitialSchema());
    }

    public function testRejectsDescendingIndex(): void
    {
        $sets = self::schemaResultSets();
        $sets[2][1]['COLLATION'] = 'D';
        $inspector = new MySqlSchemaInspector(new FakeSchemaInspectorConnection($sets));

        self::assertFalse($inspector->matchesInitialSchema());
    }

    public function testRejectsMissingColumn(): void
    {
        $sets = self::schemaResultSets();
        array_pop($sets[1]);
        $inspector = new MySqlSchemaInspector(new FakeSchemaInspectorConnection($sets));

        self::assertFalse($inspector->matchesInitialSchema());
    }

    public function testDetectsAnyExistingModuleTable(): void
    {
        $inspector = new MySqlSchemaInspector(
            new FakeSchemaInspectorConnection([[['TABLE_NAME' => 'b_cmi_event']]])
        );

        self::assertTrue($inspector->hasAnyModuleTables());
    }

    public function testDetectsEmptyDatabase(): void
    {
        $inspector = new MySqlSchemaInspector(
            new FakeSchemaInspectorConnection([[]])
        );

        self::assertFalse($inspector->hasAnyModuleTables());
    }

    private static function schemaResultSets(): array
    {
        $tables = [
            ['TABLE_NAME' => 'b_cmi_event', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
            ['TABLE_NAME' => 'b_cmi_event_data', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
            ['TABLE_NAME' => 'b_cmi_source', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
        ];

        $columns = [];
        $add = static function (
            string $table,
            string $name,
            string $type,
            bool $nullable = false,
            ?string $charset = null,
            ?string $collation = null
        ) use (&$columns): void {
            $columns[] = [
                'TABLE_NAME' => $table,
                'COLUMN_NAME' => $name,
                'COLUMN_TYPE' => $type,
                'IS_NULLABLE' => $nullable ? 'YES' : 'NO',
                'CHARACTER_SET_NAME' => $charset,
                'COLLATION_NAME' => $collation,
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ];
        };
        $text = static function (string $table, string $name, string $type, bool $nullable = false) use ($add): void {
            $add($table, $name, $type, $nullable, 'utf8mb4', 'utf8mb4_unicode_ci');
        };
        $id = static function (string $table, string $name, bool $nullable = false) use ($add): void {
            $add($table, $name, 'char(36)', $nullable, 'ascii', 'ascii_bin');
        };
        $plain = static function (string $table, string $name, string $type, bool $nullable = false) use ($add): void {
            $add($table, $name, $type, $nullable);
        };

        $t = 'b_cmi_event';
        $id($t, 'EVENT_ID');
        $text($t, 'SOURCE_CODE', 'varchar(64)');
        $text($t, 'OPERATION', 'varchar(128)');
        $text($t, 'DIRECTION', 'varchar(12)');
        $text($t, 'STATUS', 'varchar(12)');
        $text($t, 'CORRELATION_ID', 'varchar(128)', true);
        $id($t, 'PARENT_EVENT_ID', true);
        $plain($t, 'DATE_START', 'datetime');
        $plain($t, 'OCCURRED_AT', 'datetime', true);
        $plain($t, 'DATE_FINISH', 'datetime', true);
        $plain($t, 'DURATION_MS', 'bigint unsigned', true);
        $plain($t, 'HTTP_STATUS', 'smallint unsigned', true);
        $text($t, 'RESULT_CODE', 'varchar(64)', true);
        $text($t, 'ERROR_CLASS', 'varchar(255)', true);
        $text($t, 'ERROR_CODE', 'varchar(64)', true);
        $text($t, 'ERROR_MESSAGE', 'varchar(1000)', true);
        $text($t, 'ERROR_FILE', 'varchar(500)', true);
        $plain($t, 'ERROR_LINE', 'int unsigned', true);

        $t = 'b_cmi_event_data';
        $id($t, 'EVENT_ID');
        $text($t, 'REQUEST_META', 'mediumtext', true);
        $text($t, 'RESPONSE_META', 'mediumtext', true);
        $text($t, 'CONTEXT', 'mediumtext', true);
        $text($t, 'REQUEST_PAYLOAD', 'mediumtext', true);
        $text($t, 'RESPONSE_PAYLOAD', 'mediumtext', true);
        $text($t, 'ERROR_TRACE', 'mediumtext', true);

        $t = 'b_cmi_source';
        $text($t, 'CODE', 'varchar(64)');
        $text($t, 'NAME', 'varchar(255)');
        $text($t, 'DESCRIPTION', 'varchar(1000)', true);
        $plain($t, 'DATE_CREATE', 'datetime');
        $plain($t, 'DATE_UPDATE', 'datetime');

        $indexes = [];
        $addIndex = static function (string $table, string $index, bool $unique, array $names) use (&$indexes): void {
            foreach ($names as $position => $name) {
                $indexes[] = [
                    'TABLE_NAME' => $table,
                    'INDEX_NAME' => $index,
                    'NON_UNIQUE' => $unique ? '0' : '1',
                    'SEQ_IN_INDEX' => (string) ($position + 1),
                    'COLUMN_NAME' => $name,
                    'INDEX_TYPE' => 'BTREE',
                    'SUB_PART' => null,
                    'COLLATION' => 'A',
                ];
            }
        };
        $addIndex('b_cmi_event', 'PRIMARY', true, ['EVENT_ID']);
        $addIndex('b_cmi_event', 'IX_CMI_EVENT_DATE', false, ['DATE_START']);
        $addIndex('b_cmi_event', 'IX_CMI_EVENT_SOURCE_DATE', false, ['SOURCE_CODE', 'DATE_START']);
        $addIndex('b_cmi_event', 'IX_CMI_EVENT_STATUS_DATE', false, ['STATUS', 'DATE_START']);
        $addIndex('b_cmi_event', 'IX_CMI_EVENT_DIRECTION_DATE', false, ['DIRECTION', 'DATE_START']);
        $addIndex('b_cmi_event', 'IX_CMI_EVENT_CORRELATION', false, ['CORRELATION_ID']);
        $addIndex('b_cmi_event_data', 'PRIMARY', true, ['EVENT_ID']);
        $addIndex('b_cmi_source', 'PRIMARY', true, ['CODE']);

        return [$tables, $columns, $indexes];
    }
}

final class FakeSchemaInspectorConnection
{
    public function __construct(private array $resultSets)
    {
    }

    public function query(string $sql): FakeSchemaInspectorResult
    {
        return new FakeSchemaInspectorResult(array_shift($this->resultSets) ?? []);
    }
}

final class FakeSchemaInspectorResult
{
    public function __construct(private array $rows)
    {
    }

    public function fetch(): mixed
    {
        return array_shift($this->rows) ?? false;
    }
}
