<?php

declare(strict_types=1);

namespace CodeMatrix\\IntegrationInspector\\Tests\\Storage;

use CodeMatrix\\IntegrationInspector\\Storage\\SchemaInstaller;
use PHPUnit\\Framework\\TestCase;

final class SchemaInstallerTest extends TestCase
{
    public function testInstallCreatesAllThreeTablesAndCanBeRunRepeatedly(): void
    {
        $connection = new FakeSchemaConnection();
        $installer = new SchemaInstaller($connection);

        $installer->install();
        $installer->install();

        self::assertCount(6, $connection->queries);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS b_cmi_event (', $connection->queries[0]);
        self::assertStringContainsString('ENGINE=InnoDB', $connection->queries[0]);
        self::assertStringContainsString('IX_CMI_EVENT_SOURCE_DATE', $connection->queries[0]);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS b_cmi_event_data (', $connection->queries[1]);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS b_cmi_source (', $connection->queries[2]);
        self::assertStringContainsString('PRIMARY KEY (EVENT_ID)', $connection->queries[1]);
    }
}

final class FakeSchemaConnection
{
    public array $queries = [];

    public function queryExecute(string $sql): void
    {
        $this->queries[] = $sql;
    }
}
