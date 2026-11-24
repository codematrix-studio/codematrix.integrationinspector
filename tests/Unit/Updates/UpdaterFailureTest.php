<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Updates;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpdaterFailureTest extends TestCase
{
    private const REQUIRED_FILES = [
        'lib/Storage/SchemaInstaller.php',
        'lib/Storage/Migrations/SchemaMigration.php',
        'lib/Storage/Migrations/SchemaVersionStore.php',
        'lib/Storage/Migrations/MigrationLock.php',
        'lib/Storage/Migrations/InitialSchemaVerifier.php',
        'lib/Storage/Migrations/MySqlSchemaVersionStore.php',
        'lib/Storage/Migrations/MySqlMigrationLock.php',
        'lib/Storage/Migrations/MySqlSchemaInspector.php',
        'lib/Storage/Migrations/InitialSchemaMigration.php',
        'lib/Storage/Migrations/MigrationRunner.php',
    ];

    public function testMissingMigrationSourceStopsBeforeCopyAndDatabaseAccess(): void
    {
        $result = $this->runUpdater(completeSource: false);

        self::assertSame(0, $result['copy_calls']);
        self::assertFalse($result['database_accessed']);
        self::assertNotEmpty($result['error_message']);
    }

    public function testCopyFailureStopsBeforeDatabaseAccess(): void
    {
        $result = $this->runUpdater(completeSource: true);

        self::assertSame(1, $result['copy_calls']);
        self::assertFalse($result['database_accessed']);
        self::assertNotEmpty($result['error_message']);
    }

    public function testChecksumMismatchStopsBeforeDatabaseAccess(): void
    {
        $result = $this->runUpdater(completeSource: true, copySucceeds: true, corruptCopy: true);

        self::assertSame(1, $result['copy_calls']);
        self::assertFalse($result['database_accessed']);
        self::assertNotEmpty($result['error_message']);
    }

    private function runUpdater(
        bool $completeSource,
        bool $copySucceeds = false,
        bool $corruptCopy = false
    ): array
    {
        $root = sys_get_temp_dir() . '/cmi-updater-' . bin2hex(random_bytes(8));
        $source = $root . '/updates/0.1.1/modules/codematrix.integrationinspector';
        $updaterScript = dirname(__DIR__, 3) . '/updates/0.1.1/updater.php';
        $harness = $root . '/harness.php';

        if (!mkdir($source . '/lib/Storage/Migrations', 0777, true) && !is_dir($source)) {
            throw new RuntimeException('Could not create updater test directory.');
        }

        try {
            $files = $completeSource ? self::REQUIRED_FILES : array_slice(self::REQUIRED_FILES, 0, -1);
            foreach ($files as $relativeFile) {
                $file = $source . '/' . $relativeFile;
                if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0777, true) && !is_dir(dirname($file))) {
                    throw new RuntimeException('Could not create updater source directory.');
                }
                file_put_contents($file, '<?php // updater test fixture');
            }

            $harnessCode = <<<'PHP'
<?php

namespace Bitrix\Main {
    final class ModuleManager
    {
        public static function isModuleInstalled(string $moduleId): bool
        {
            return true;
        }
    }

    final class Application
    {
        public static bool $databaseAccessed = false;

        public static function getConnection()
        {
            self::$databaseAccessed = true;
            throw new \RuntimeException('Database access must not happen in this test.');
        }
    }
}

namespace {
    final class FakeUpdater
    {
        public int $copyCalls = 0;
        public string $curModulePath;

        public function __construct(
            string $modulePath,
            private bool $copySucceeds,
            private bool $corruptCopy
        ) {
            $this->curModulePath = $modulePath;
        }

        public function CopyDirFiles(string $source, string $destination): bool
        {
            $this->copyCalls++;
            if (!$this->copySucceeds) {
                return false;
            }

            $documentRoot = $_SERVER['DOCUMENT_ROOT'];
            $sourcePath = $documentRoot . $source;
            $destinationPath = $documentRoot . $destination;
            mkdir($destinationPath, 0777, true);

            $iterator = new \\RecursiveIteratorIterator(
                new \\RecursiveDirectoryIterator($sourcePath, \\FilesystemIterator::SKIP_DOTS),
                \\RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $target = $destinationPath . '/' . $iterator->getSubPathName();
                if ($item->isDir()) {
                    mkdir($target, 0777, true);
                } else {
                    copy($item->getPathname(), $target);
                }
            }

            if ($this->corruptCopy) {
                file_put_contents($destinationPath . '/Migrations/MigrationRunner.php', 'corrupted');
            }

            return true;
        }
    }

    $_SERVER['DOCUMENT_ROOT'] = __DOC_ROOT__;
    $updater = new FakeUpdater(
        '/updates/0.1.1/modules/codematrix.integrationinspector',
        __COPY_SUCCEEDS__,
        __CORRUPT_COPY__
    );
    $errorMessage = '';

    include __UPDATER__;

    echo json_encode([
        'copy_calls' => $updater->copyCalls,
        'database_accessed' => \Bitrix\Main\Application::$databaseAccessed,
        'error_message' => $errorMessage,
    ], JSON_THROW_ON_ERROR);
}
PHP;
            $harnessCode = str_replace(
                ['__DOC_ROOT__', '__UPDATER__', '__COPY_SUCCEEDS__', '__CORRUPT_COPY__'],
                [
                    var_export($root, true),
                    var_export($updaterScript, true),
                    var_export($copySucceeds, true),
                    var_export($corruptCopy, true),
                ],
                $harnessCode
            );
            file_put_contents($harness, $harnessCode);

            $process = proc_open(
                [PHP_BINARY, $harness],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );

            if (!is_resource($process)) {
                throw new RuntimeException('Could not start updater test process.');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new RuntimeException('Updater test process failed: ' . $stderr . $stdout);
            }

            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($result);

            return $result;
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
