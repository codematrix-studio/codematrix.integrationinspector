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

if (!isset($updater) || !is_object($updater) || !method_exists($updater, 'CopyDirFiles')) {
    $errorMessage = 'Не удалось обновить CodeMatrix Integration Inspector: недоступен механизм копирования файлов Bitrix.';
    return;
}

$moduleRelativePath = 'modules/' . $moduleId;
$documentRoot = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\');
$modulePath = $documentRoot . '/local/' . $moduleRelativePath;

// CUpdater::CopyDirFiles() treats a missing source directory as a successful no-op.
// Validate the extracted update source first so stale destination files cannot
// make an incomplete update look successful.
$updateModuleRelativePath = isset($updater->curModulePath) && is_string($updater->curModulePath)
    ? '/' . ltrim(rtrim($updater->curModulePath, '/\\'), '/\\')
    : '';
$updateModulePath = $documentRoot . $updateModuleRelativePath;

if (
    $documentRoot === ''
    || $updateModulePath === ''
    || !is_dir($updateModulePath . '/lib/Storage')
) {
    $errorMessage = 'Не удалось обновить CodeMatrix Integration Inspector: в пакете отсутствуют файлы миграций.';
    return;
}

try {
    // CUpdater::CopyFiles() always resolves relative destinations under /bitrix.
    // CopyDirFiles() accepts an absolute site-relative destination, so the
    // migration classes are installed into this module's actual /local path.
    if (!$updater->CopyDirFiles(
        $updateModuleRelativePath . '/lib/Storage',
        '/local/' . $moduleRelativePath . '/lib/Storage'
    )) {
        throw new RuntimeException('Bitrix failed to copy the migration files.');
    }

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

        $sourceFile = $updateModulePath . $relativeFile;
        $sourceHash = is_file($sourceFile) ? hash_file('sha256', $sourceFile) : false;
        $installedHash = hash_file('sha256', $file);

        if (
            !is_string($sourceHash)
            || !is_string($installedHash)
            || !hash_equals($sourceHash, $installedHash)
        ) {
            throw new RuntimeException(
                'CodeMatrix Integration Inspector update did not install the expected migration files.'
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
