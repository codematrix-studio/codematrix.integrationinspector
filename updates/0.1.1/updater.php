<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use CodeMatrix\IntegrationInspector\Storage\SchemaInstaller;
use CodeMatrix\IntegrationInspector\Storage\Migrations\InitialSchemaMigration;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MigrationRunner;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlMigrationLock;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlSchemaInspector;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlSchemaVersionStore;

/**
 * CodeMatrix Integration Inspector 0.1.1 update.
 *
 * Bitrix executes updater.php before copying the rest of the update package.
 * Copy the migration implementation first, then load it from local/modules.
 */
if (!isset($updater) || !is_object($updater) || !method_exists($updater, 'CopyFiles')) {
    throw new RuntimeException('Bitrix update context does not provide CopyFiles().');
}

$moduleId = 'codematrix.integrationinspector';
$moduleRelativePath = 'modules/' . $moduleId;
$modulePath = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\')
    . '/local/' . $moduleRelativePath;

// The update archive contains the current lib/Storage tree at its root.
// Copy it before loading MigrationRunner: the new module API is unavailable
// until the update script has explicitly copied the required files.
$updater->CopyFiles('lib/Storage', $moduleRelativePath . '/lib/Storage');

$requiredFiles = [
    '/lib/Storage/SchemaInstaller.php',
    '/lib/Storage/Migrations/SchemaMigration.php',
    '/lib/Storage/Migrations/SchemaVersionStore.php',
    '/lib/Storage/Migrations/MigrationLock.php',
    '/lib/Storage/Migrations/InitialSchemaVerifier.php',
    '/lib/Storage/Migrations/MySqlSchemaVersionStore.php',
    '/lib/Storage/Migrations/MySqlMigrationLock.php',
    '/lib/Storage/Migrations/MySqlSchemaInspector.php',
    '/lib/Storage/Migrations/InitialSchemaMigration.php',
    '/lib/Storage/Migrations/MigrationRunner.php',
];

foreach ($requiredFiles as $relativeFile) {
    $file = $modulePath . $relativeFile;
    if (!is_file($file)) {
        throw new RuntimeException(
            'CodeMatrix Integration Inspector update is incomplete: required migration file is missing.'
        );
    }

    require_once $file;
}

try {
    $connection = Application::getConnection();
    $runner = new MigrationRunner(
        new MySqlSchemaVersionStore($connection),
        [
            new InitialSchemaMigration(
                new SchemaInstaller($connection),
                new MySqlSchemaInspector($connection)
            ),
        ],
        new MySqlMigrationLock($connection)
    );

    $runner->run();
} catch (Throwable $exception) {
    throw new RuntimeException(
        'CodeMatrix Integration Inspector database migration failed. '
        . 'The module update must not be considered complete. '
        . 'Check the database schema and update log before retrying.',
        0,
        $exception
    );
}
