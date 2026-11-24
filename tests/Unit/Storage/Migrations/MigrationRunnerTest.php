<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Storage\Migrations;

use CodeMatrix\IntegrationInspector\Storage\Migrations\MigrationRunner;
use CodeMatrix\IntegrationInspector\Storage\Migrations\SchemaMigration;
use CodeMatrix\IntegrationInspector\Storage\Migrations\SchemaVersionStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationRunnerTest extends TestCase
{
    public function testMigrationsRunInOrderAndVersionIsAdvancedAfterVerification(): void
    {
        $store = new InMemorySchemaVersionStore();
        $first = new FakeSchemaMigration(1);
        $second = new FakeSchemaMigration(2);

        $runner = new MigrationRunner($store, [$second, $first]);

        self::assertSame(2, $runner->run());
        self::assertSame([1, 2], $store->writtenVersions);
        self::assertSame(1, $first->runs);
        self::assertSame(1, $second->runs);
    }

    public function testAlreadyAppliedMigrationsAreSkipped(): void
    {
        $store = new InMemorySchemaVersionStore(1);
        $first = new FakeSchemaMigration(1);
        $second = new FakeSchemaMigration(2);

        $runner = new MigrationRunner($store, [$first, $second]);

        self::assertSame(2, $runner->run());
        self::assertSame(0, $first->runs);
        self::assertSame(1, $second->runs);
    }

    public function testFailedMigrationDoesNotAdvanceVersion(): void
    {
        $store = new InMemorySchemaVersionStore();
        $migration = new FakeSchemaMigration(1, fail: true);
        $runner = new MigrationRunner($store, [$migration]);

        try {
            $runner->run();
            self::fail('Expected migration failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Schema migration 1 failed', $exception->getMessage());
        }

        self::assertSame(0, $store->version);
        self::assertSame([], $store->writtenVersions);
    }

    public function testMigrationMustPassPhysicalSchemaVerificationBeforeVersionIsSaved(): void
    {
        $store = new InMemorySchemaVersionStore();
        $migration = new FakeSchemaMigration(1, verified: false);
        $runner = new MigrationRunner($store, [$migration]);

        try {
            $runner->run();
            self::fail('Expected verification failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('did not produce its expected schema', $exception->getPrevious()?->getMessage());
        }

        self::assertSame(0, $store->version);
        self::assertSame([], $store->writtenVersions);
    }

    public function testGapInMigrationSequenceIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected 1, got 2');

        new MigrationRunner(new InMemorySchemaVersionStore(), [new FakeSchemaMigration(2)]);
    }

    public function testUnknownNewerDatabaseVersionIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('newer than supported version');

        (new MigrationRunner(
            new InMemorySchemaVersionStore(2),
            [new FakeSchemaMigration(1)]
        ))->run();
    }

    public function testEmptyMigrationSetDoesNotResetAnEmptySchemaVersion(): void
    {
        $store = new InMemorySchemaVersionStore();

        self::assertSame(0, (new MigrationRunner($store, []))->run());
        self::assertSame([], $store->writtenVersions);
    }
}

final class InMemorySchemaVersionStore implements SchemaVersionStore
{
    public int $version;
    public array $writtenVersions = [];
    public bool $storageEnsured = false;

    public function __construct(int $version = 0)
    {
        $this->version = $version;
    }

    public function ensureStorage(): void
    {
        $this->storageEnsured = true;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
        $this->writtenVersions[] = $version;
    }
}

final class FakeSchemaMigration implements SchemaMigration
{
    public int $runs = 0;

    public function __construct(
        private int $migrationVersion,
        private bool $fail = false,
        private bool $verified = true
    ) {
    }

    public function version(): int
    {
        return $this->migrationVersion;
    }

    public function description(): string
    {
        return 'Fake migration ' . $this->migrationVersion;
    }

    public function up(): void
    {
        $this->runs++;

        if ($this->fail) {
            throw new RuntimeException('DDL failure');
        }
    }

    public function isApplied(): bool
    {
        return $this->verified;
    }
}
