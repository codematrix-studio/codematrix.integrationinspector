<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

use CodeMatrix\IntegrationInspector\Storage\SchemaInstaller;

/**
 * Начальная схема 0.1.0 и baseline для существующих установок.
 *
 * Если найдена хотя бы одна таблица модуля, DDL не выполняется:
 * сначала требуется точное совпадение всей схемы. Это исключает
 * автоматическое "достраивание" неизвестной/частичной установки.
 */
final class InitialSchemaMigration implements SchemaMigration
{
    private SchemaInstaller $installer;
    private InitialSchemaVerifier $verifier;

    public function __construct(SchemaInstaller $installer, InitialSchemaVerifier $verifier)
    {
        $this->installer = $installer;
        $this->verifier = $verifier;
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Initial CodeMatrix Integration Inspector schema 0.1.0';
    }

    public function up(): void
    {
        if ($this->verifier->hasAnyModuleTables()) {
            return;
        }

        $this->installer->install();
    }

    public function isApplied(): bool
    {
        return $this->verifier->matchesInitialSchema();
    }
}
