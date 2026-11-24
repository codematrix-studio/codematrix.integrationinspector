<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage\Migrations;

interface InitialSchemaVerifier
{
    public function hasAnyModuleTables(): bool;

    public function matchesInitialSchema(): bool;
}
