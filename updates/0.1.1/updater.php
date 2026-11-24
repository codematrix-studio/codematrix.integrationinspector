<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;
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
 * The update package itself is available, so copy the migration code first.
 */
$moduleId = 'codematrix.integrationinspector';

// Bitrix executes update scripts even when the corresponding module is not installed.
if (!ModuleManager::isModuleInstalled($moduleId)) {
    return;
}

if (!isset($updater) || !is_object($updater) || !method_exists($updater, 'CopyFiles')) {
    $errorMessage = 'Не удалось обновить CodeMatrix Integration Inspector: недоступен механизм копирования файлов Bitrix.';
    return;
}

$moduleRelativePath = 'modules/' . $moduleId;
$modulePath = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\')
    . '/local/' . $moduleRelativePath;

try {
    // The archive contains the current lib/Storage tree at its root.
    // New module API is unavailable until the update script copies these files.
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
    error_log(
        '[CodeMatrix Integration Inspector] Update 0.1.1 failed: '
        . get_class($exception) . ': ' . $exception->getMessage()
    );

    // Bitrix recognizes $errorMessage and marks the update as failed.
    $errorMessage = 'Не удалось обновить CodeMatrix Integration Inspector: ошибка миграции схемы БД. '
        . 'Проверьте журнал ошибок и состояние таблиц перед повторной попыткой.';
}
