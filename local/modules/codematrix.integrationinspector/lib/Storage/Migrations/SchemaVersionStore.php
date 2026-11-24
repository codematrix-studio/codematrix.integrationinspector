<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

/**
 * Хранилище последней полностью применённой версии схемы.
 *
 * Реализация обязана создавать служебную таблицу идемпотентно.
 */
interface SchemaVersionStore
{
    public function ensureStorage(): void;

    public function getVersion(): int;

    public function setVersion(int $version): void;
}
