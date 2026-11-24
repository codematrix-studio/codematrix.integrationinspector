<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

/**
 * Одно повторяемое изменение физической схемы БД.
 *
 * up() обязан безопасно обрабатывать повторный запуск после частичного DDL.
 * isApplied() проверяет фактическое состояние БД, а не только запись версии.
 */
interface SchemaMigration
{
    public function version(): int;

    public function description(): string;

    public function up(): void;

    public function isApplied(): bool;
}
