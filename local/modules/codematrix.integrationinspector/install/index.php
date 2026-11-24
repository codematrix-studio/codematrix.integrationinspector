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

            // Копируем только адресный файл входа в административную часть Bitrix.
            if (!copy(dirname(__DIR__) . '/admin/cmi_integrationinspector_events.php', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/cmi_integrationinspector_events.php')) {
                throw new \RuntimeException('Не удалось установить административную страницу модуля.');
            }

            return true;
        } catch (Throwable $exception) {
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
        DeleteFile($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/cmi_integrationinspector_events.php');
        UnRegisterModule($this->MODULE_ID);

        // При обычном удалении модуля история диагностики сохраняется.
        return true;
    }
}
