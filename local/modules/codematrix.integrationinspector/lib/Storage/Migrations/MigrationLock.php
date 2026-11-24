<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

interface MigrationLock
{
    public function acquire(): bool;

    public function release(): void;
}
