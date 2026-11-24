<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

use RuntimeException;
use Throwable;

/**
 * Последовательно применяет миграции и фиксирует версию только после проверки.
 *
 * Runner намеренно не выбирает точку запуска: install flow или updater.php
 * должны вызывать его явно после подключения модуля.
 */
final class MigrationRunner
{
    /** @var list<SchemaMigration> */
    private array $migrations;

    private SchemaVersionStore $versionStore;

    private MigrationLock $lock;

    /**
     * @param iterable<SchemaMigration> $migrations
     */
    public function __construct(SchemaVersionStore $versionStore, iterable $migrations, MigrationLock $lock)
    {
        $this->versionStore = $versionStore;
        $this->lock = $lock;
        $this->migrations = [];

        foreach ($migrations as $migration) {
            $this->migrations[] = $migration;
        }

        usort(
            $this->migrations,
            static fn (SchemaMigration $left, SchemaMigration $right): int =>
                $left->version() <=> $right->version()
        );

        $this->validateMigrationSequence();
    }

    /**
     * Возвращает версию схемы после успешного выполнения всех доступных миграций.
     */
    public function run(): int
    {
        if (!$this->lock->acquire()) {
            throw new RuntimeException('Another schema migration process holds the MySQL advisory lock.');
        }

        try {
            $this->versionStore->ensureStorage();
            $currentVersion = $this->versionStore->getVersion();
            $latestVersion = $this->migrations === []
                ? 0
                : $this->migrations[array_key_last($this->migrations)]->version();

            if ($currentVersion > $latestVersion) {
                throw new RuntimeException(
                    sprintf(
                        'Database schema version %d is newer than supported version %d.',
                        $currentVersion,
                        $latestVersion
                    )
                );
            }

            foreach ($this->migrations as $migration) {
                if ($migration->version() <= $currentVersion) {
                    continue;
                }

                try {
                    $migration->up();

                    if (!$migration->isApplied()) {
                        throw new RuntimeException(
                            sprintf(
                                'Migration %d did not produce its expected schema.',
                                $migration->version()
                            )
                        );
                    }

                    // Версия фиксируется последней: после DDL и фактической проверки.
                    $this->versionStore->setVersion($migration->version());
                    $currentVersion = $migration->version();
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        sprintf(
                            'Schema migration %d failed (%s). Schema version remains %d.',
                            $migration->version(),
                            $migration->description(),
                            $currentVersion
                        ),
                        0,
                        $exception
                    );
                }
            }

            return $currentVersion;
        } finally {
            $this->lock->release();
        }
    }

    private function validateMigrationSequence(): void
    {
        $expectedVersion = 1;

        foreach ($this->migrations as $migration) {
            if ($migration->version() !== $expectedVersion) {
                throw new RuntimeException(
                    sprintf(
                        'Migration sequence must be contiguous from version 1; expected %d, got %d.',
                        $expectedVersion,
                        $migration->version()
                    )
                );
            }

            if ($migration->description() === '') {
                throw new RuntimeException(
                    sprintf('Migration %d must have a non-empty description.', $migration->version())
                );
            }

            $expectedVersion++;
        }
    }
}
