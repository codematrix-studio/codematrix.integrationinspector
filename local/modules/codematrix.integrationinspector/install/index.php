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
            require_once dirname(__DIR__) . '/include.php';

            $installer = new SchemaInstaller(Application::getConnection());
            $installer->install();

            RegisterModule($this->MODULE_ID);

            return true;
        } catch (Throwable $exception) {
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
