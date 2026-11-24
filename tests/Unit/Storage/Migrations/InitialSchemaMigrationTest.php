<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Storage\Migrations;

use CodeMatrix\IntegrationInspector\Storage\Migrations\InitialSchemaMigration;
use CodeMatrix\IntegrationInspector\Storage\Migrations\InitialSchemaVerifier;
use CodeMatrix\IntegrationInspector\Storage\SchemaInstaller;
use PHPUnit\Framework\TestCase;

final class InitialSchemaMigrationTest extends TestCase
{
    public function testFreshDatabaseCreatesInitialTablesAndVerifiesSchema(): void
    {
        $connection = new FakeInitialSchemaConnection();
        $verifier = new FakeInitialSchemaVerifier(false, true);
        $migration = new InitialSchemaMigration(new SchemaInstaller($connection), $verifier);

        $migration->up();

        self::assertSame(3, count($connection->queries));
        self::assertTrue($migration->isApplied());
    }

    public function testExistingMatchingSchemaIsAcceptedAsBaselineWithoutDdl(): void
    {
        $connection = new FakeInitialSchemaConnection();
        $verifier = new FakeInitialSchemaVerifier(true, true);
        $migration = new InitialSchemaMigration(new SchemaInstaller($connection), $verifier);

        $migration->up();

        self::assertSame([], $connection->queries);
        self::assertTrue($migration->isApplied());
    }

    public function testPartialExistingSchemaIsNotAutomaticallyModified(): void
    {
        $connection = new FakeInitialSchemaConnection();
        $verifier = new FakeInitialSchemaVerifier(true, false);
        $migration = new InitialSchemaMigration(new SchemaInstaller($connection), $verifier);

        $migration->up();

        self::assertSame([], $connection->queries);
        self::assertFalse($migration->isApplied());
    }
}

final class FakeInitialSchemaConnection
{
    public array $queries = [];

    public function queryExecute(string $sql): void
    {
        $this->queries[] = $sql;
    }
}

final class FakeInitialSchemaVerifier implements InitialSchemaVerifier
{
    public function __construct(
        private bool $hasTables,
        private bool $matches
    ) {
    }

    public function hasAnyModuleTables(): bool
    {
        return $this->hasTables;
    }

    public function matchesInitialSchema(): bool
    {
        return $this->matches;
    }
}
