<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use CodeMatrix\IntegrationInspector\Storage\SchemaInstaller;

class codematrix_integrationinspector extends CModule
{
    public $MODULE_ID = 'codematrix.integrationinspector';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'CodeMatrix Integration Inspector';
    public $MODULE_DESCRIPTION = 'Диагностика интеграций для 1С-Битрикс.';
    public $PARTNER_NAME = 'CodeMatrix STUDIO';
    public $PARTNER_URI = 'https://github.com/codematrix-studio';

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

        try {
            // Во время установки модуль ещё не зарегистрирован, поэтому Bitrix
            // может искать его классы относительно каталога /bitrix/modules.
            // Подключаем установщик напрямую из каталога local/modules.
            require_once dirname(__DIR__) . '/lib/Storage/SchemaInstaller.php';

            $installer = new SchemaInstaller(Application::getConnection());
            $installer->install();

            RegisterModule($this->MODULE_ID);

            // Регистрируем остальные классы модуля только после того,
            // как Bitrix получил корректный путь к модулю.
            require_once dirname(__DIR__) . '/include.php';

            // Не перезаписываем чужую административную страницу с тем же именем.
            $this->installAdminEntryPoint();

            // Устанавливаем только собственные стили и изображения административного меню.
            if (!CopyDirFiles(
                __DIR__ . '/themes',
                $_SERVER['DOCUMENT_ROOT'] . '/local/themes',
                true,
                true
            )) {
                throw new \RuntimeException('Не удалось установить иконки административного меню.');
            }

            return true;
        } catch (Throwable $exception) {
            $this->removeAdminEntryPoint();
            $this->removeThemeAssets();

            if (\Bitrix\Main\ModuleManager::isModuleInstalled($this->MODULE_ID)) {
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
            $isOwned = is_string($existing) && (
                $existing === $expected
                || str_contains($existing, 'CodeMatrix Integration Inspector: owned admin entry point.')
            );

            if (!$isOwned) {
                throw new \RuntimeException(
                    'Файл /bitrix/admin/cmi_integrationinspector_events.php уже существует и не принадлежит модулю.'
                );
            }
        }

        // Для принадлежащего модулю wrapper разрешено обновление его содержимого.
        if (!copy($source, $target)) {
            throw new \RuntimeException('Не удалось установить административную страницу модуля.');
        }
    }

    private function removeAdminEntryPoint(): void
    {
        $source = dirname(__DIR__) . '/admin/cmi_integrationinspector_events.php';
        $target = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/cmi_integrationinspector_events.php';

        if (!is_file($target) || !is_file($source)) {
            return;
        }

        $existing = file_get_contents($target);
        $expected = file_get_contents($source);
        $isOwned = is_string($existing) && (
            $existing === $expected
            || str_contains($existing, 'CodeMatrix Integration Inspector: owned admin entry point.')
        );

        // Удаляем только wrapper с маркером владения или legacy-копию исходника.
        if ($isOwned) {
            unlink($target);
        }
    }

    private function removeThemeAssets(): void
    {
        // Удаляем только файлы иконок этого модуля, не затрагивая общие темы Bitrix.
        $themeDirectory = $_SERVER['DOCUMENT_ROOT'] . '/local/themes/.default';
        $themeFiles = [
            $themeDirectory . '/codematrix.integrationinspector.css',
            $themeDirectory . '/icons/codematrix.integrationinspector/menu.svg',
            $themeDirectory . '/icons/codematrix.integrationinspector/page.svg',
        ];

        foreach ($themeFiles as $themeFile) {
            if (is_file($themeFile)) {
                unlink($themeFile);
            }
        }

        $iconDirectory = $themeDirectory . '/icons/codematrix.integrationinspector';
        if (is_dir($iconDirectory)) {
            rmdir($iconDirectory);
        }

        $iconsDirectory = $themeDirectory . '/icons';
        if (is_dir($iconsDirectory) && count(scandir($iconsDirectory)) === 2) {
            rmdir($iconsDirectory);
        }
    }
}
