<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use CodeMatrix\IntegrationInspector\Storage\SchemaInstaller;
use CodeMatrix\IntegrationInspector\Storage\Migrations\InitialSchemaMigration;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MigrationRunner;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlMigrationLock;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlSchemaInspector;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlSchemaVersionStore;

class codematrix_integrationinspector extends CModule
{
    public $MODULE_ID = 'codematrix.integrationinspector';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'CodeMatrix Integration Inspector';
    public $MODULE_DESCRIPTION = 'Диагностика интеграций для 1С-Битрикс.';
    public $PARTNER_NAME = 'CodeMatrix STUDIO';
    public $PARTNER_URI = 'https://github.com/codematrix-studio';

    private bool $adminEntryPointCreatedDuringInstall = false;

    /** @var list<string> */
    private array $themeAssetsCreatedDuringInstall = [];

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        global $APPLICATION;

        // Повторная установка не должна снимать регистрацию уже установленного модуля.
        $moduleWasInstalled = null;

        try {
            $moduleWasInstalled = \Bitrix\Main\ModuleManager::isModuleInstalled($this->MODULE_ID);
            // Во время установки модуль ещё не зарегистрирован, поэтому Bitrix
            // может искать классы относительно /bitrix/modules. Подключаем
            // миграционные классы напрямую из local/modules.
            $moduleLib = dirname(__DIR__) . '/lib/Storage/';
            foreach ([
                'SchemaInstaller.php',
                'Migrations/SchemaMigration.php',
                'Migrations/SchemaVersionStore.php',
                'Migrations/MigrationLock.php',
                'Migrations/InitialSchemaVerifier.php',
                'Migrations/MySqlSchemaVersionStore.php',
                'Migrations/MySqlMigrationLock.php',
                'Migrations/MySqlSchemaInspector.php',
                'Migrations/InitialSchemaMigration.php',
                'Migrations/MigrationRunner.php',
            ] as $classFile) {
                require_once $moduleLib . $classFile;
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

            RegisterModule($this->MODULE_ID);

            // Регистрируем остальные классы модуля только после того,
            // как Bitrix получил корректный путь к модулю.
            require_once dirname(__DIR__) . '/include.php';

            // Не перезаписываем чужую административную страницу с тем же именем.
            $this->installAdminEntryPoint();

            // Не перезаписываем файлы темы, которые уже существуют и не совпадают с нашими.
            $this->installThemeAssets();

            \CAgent::AddAgent(
                '\\CodeMatrix\\IntegrationInspector\\Storage\\EventCleanup::run();',
                $this->MODULE_ID,
                'N',
                86400,
                '',
                'Y'
            );

            return true;
        } catch (Throwable $exception) {
            $this->removeAdminEntryPoint(true);
            $this->removeThemeAssets(true);

            if (
                $moduleWasInstalled === false
                && \Bitrix\Main\ModuleManager::isModuleInstalled($this->MODULE_ID)
            ) {
                UnRegisterModule($this->MODULE_ID);
            }

            $APPLICATION->ThrowException(
                'Не удалось установить CodeMatrix Integration Inspector: ' . $exception->getMessage()
            );

            return false;
        }
    }

    public function DoUninstall()
    {
        $this->removeAdminEntryPoint();

        $this->removeThemeAssets();

        \CAgent::RemoveAgent(
            '\\CodeMatrix\\IntegrationInspector\\Storage\\EventCleanup::run();',
            $this->MODULE_ID
        );

        UnRegisterModule($this->MODULE_ID);

        // При обычном удалении модуля история диагностики сохраняется.
        return true;
    }

    private function installAdminEntryPoint(): void
    {
        $source = dirname(__DIR__) . '/admin/cmi_integrationinspector_events.php';
        $target = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/cmi_integrationinspector_events.php';

        $expected = file_get_contents($source);
        if ($expected === false) {
            throw new \RuntimeException('Не удалось прочитать административную страницу модуля.');
        }

        if (is_file($target)) {
            $existing = file_get_contents($target);
            $isOwned = is_string($existing) && $this->isOwnedAdminEntryPoint($existing, $expected);

            if (!$isOwned) {
                throw new \RuntimeException(
                    'Файл /bitrix/admin/cmi_integrationinspector_events.php уже существует и не принадлежит модулю.'
                );
            }

            // Текущий и известный legacy-wrapper уже принадлежат модулю.
            // Не переписываем их: повторная установка остаётся идемпотентной.
            return;
        }

        $this->adminEntryPointCreatedDuringInstall = true;

        if (!copy($source, $target)) {
            throw new \RuntimeException('Не удалось установить административную страницу модуля.');
        }
    }

    private function removeAdminEntryPoint(bool $onlyIfCreatedDuringInstall = false): void
    {
        if ($onlyIfCreatedDuringInstall && !$this->adminEntryPointCreatedDuringInstall) {
            return;
        }

        $target = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/cmi_integrationinspector_events.php';

        if ($onlyIfCreatedDuringInstall && $this->adminEntryPointCreatedDuringInstall) {
            if (is_file($target)) {
                unlink($target);
            }

            return;
        }

        $source = dirname(__DIR__) . '/admin/cmi_integrationinspector_events.php';

        if (!is_file($target) || !is_file($source)) {
            return;
        }

        $existing = file_get_contents($target);
        $expected = file_get_contents($source);
        $isOwned = is_string($existing)
            && is_string($expected)
            && $this->isOwnedAdminEntryPoint($existing, $expected);

        // Удаляем только известные версии wrapper, принадлежащие модулю.
        if ($isOwned) {
            unlink($target);
        }
    }

    /**
     * Проверяет принадлежность административного wrapper модулю.
     *
     * Поддерживаются только точная текущая версия и известная legacy-версия.
     */
    private function isOwnedAdminEntryPoint(string $existing, string $expected): bool
    {
        $legacy = <<<'PHP'
<?php

declare(strict_types=1);

// Точка входа копируется установщиком в /bitrix/admin.
require $_SERVER['DOCUMENT_ROOT'] . '/local/modules/codematrix.integrationinspector/lib/Admin/EventsPage.php';
PHP;

        $legacy .= "\n";

        // Учитываем только различия формата перевода строк, не меняя содержимое.
        $normalize = static fn (string $content): string => str_replace(
            ["\r\n", "\r"],
            "\n",
            $content
        );

        $existing = $normalize($existing);

        return $existing === $normalize($expected)
            || $existing === $normalize($legacy);
    }

    /**
     * Устанавливает ресурсы темы, не перезаписывая чужие или изменённые файлы.
     */
    private function installThemeAssets(): void
    {
        $sourceDirectory = __DIR__ . '/themes/.default';
        $targetDirectory = $_SERVER['DOCUMENT_ROOT'] . '/local/themes/.default';
        $relativeFiles = [
            'codematrix.integrationinspector.css',
            'icons/codematrix.integrationinspector/menu.svg',
            'icons/codematrix.integrationinspector/page.svg',
        ];

        foreach ($relativeFiles as $relativeFile) {
            $source = $sourceDirectory . '/' . $relativeFile;
            $target = $targetDirectory . '/' . $relativeFile;
            $content = file_get_contents($source);

            if ($content === false) {
                throw new \RuntimeException('Не удалось прочитать ресурс темы: ' . $relativeFile);
            }

            if (is_file($target)) {
                if (file_get_contents($target) !== $content) {
                    throw new \RuntimeException(
                        'Файл ресурса темы уже существует и отличается от файла модуля: ' . $relativeFile
                    );
                }

                continue;
            }

            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Не удалось создать каталог ресурсов темы.');
            }

            $this->themeAssetsCreatedDuringInstall[] = $target;

            if (!copy($source, $target)) {
                throw new \RuntimeException('Не удалось установить ресурс темы: ' . $relativeFile);
            }
        }
    }

    private function removeThemeAssets(bool $onlyCreatedDuringInstall = false): void
    {
        // Удаляем только неизменённые файлы, совпадающие с поставляемыми модулем.
        $sourceDirectory = __DIR__ . '/themes/.default';
        $targetDirectory = $_SERVER['DOCUMENT_ROOT'] . '/local/themes/.default';
        $relativeFiles = [
            'codematrix.integrationinspector.css',
            'icons/codematrix.integrationinspector/menu.svg',
            'icons/codematrix.integrationinspector/page.svg',
        ];

        foreach ($relativeFiles as $relativeFile) {
            $source = $sourceDirectory . '/' . $relativeFile;
            $target = $targetDirectory . '/' . $relativeFile;

            if (
                $onlyCreatedDuringInstall
                && !in_array($target, $this->themeAssetsCreatedDuringInstall, true)
            ) {
                continue;
            }

            if (!is_file($source) || !is_file($target)) {
                continue;
            }

            if ($onlyCreatedDuringInstall) {
                unlink($target);
                continue;
            }

            $sourceContent = file_get_contents($source);
            $targetContent = file_get_contents($target);

            if (is_string($sourceContent) && $targetContent === $sourceContent) {
                unlink($target);
            }
        }

        // При откате не удаляем каталоги: они могли существовать до установки.
        // При обычном удалении убираем только пустые каталоги ресурсов модуля.
        if (!$onlyCreatedDuringInstall) {
            $iconDirectory = $targetDirectory . '/icons/codematrix.integrationinspector';
            if (is_dir($iconDirectory)) {
                rmdir($iconDirectory);
            }

            $iconsDirectory = $targetDirectory . '/icons';
            if (is_dir($iconsDirectory) && count(scandir($iconsDirectory)) === 2) {
                rmdir($iconsDirectory);
            }
        }
    }
}
