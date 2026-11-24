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
            // During installation the module is not registered yet, so Bitrix
            // may resolve its module-relative autoload paths under /bitrix/modules.
            // Load the installer from this local module directory explicitly.
            require_once dirname(__DIR__) . '/lib/Storage/SchemaInstaller.php';

            $installer = new SchemaInstaller(Application::getConnection());
            $installer->install();

            RegisterModule($this->MODULE_ID);

            // Register the remaining module classes only after the module path
            // is known to Bitrix.
            require_once dirname(__DIR__) . '/include.php';

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
        UnRegisterModule($this->MODULE_ID);

        // Diagnostic history is intentionally preserved on ordinary uninstall.
        return true;
    }
}
