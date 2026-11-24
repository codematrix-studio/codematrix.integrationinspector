<?php

declare(strict_types=1);

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

$moduleId = 'codematrix.integrationinspector';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Настройки модуля доступны только администраторам.');
}

if (!Loader::includeModule($moduleId)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    echo '<div class="adm-info-message adm-info-message-red">Не удалось подключить CodeMatrix Integration Inspector.</div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $retentionDaysRaw = $_POST['retention_days'] ?? '';
    $connectionNameRaw = $_POST['connection_name'] ?? '';

    $retentionDays = is_scalar($retentionDaysRaw) && preg_match('/^\d+$/', (string) $retentionDaysRaw) === 1
        ? (int) $retentionDaysRaw
        : 0;
    $connectionName = is_string($connectionNameRaw) ? trim($connectionNameRaw) : '';

    if ($retentionDays < 1 || $retentionDays > 3650) {
        $errors[] = 'Срок хранения должен быть целым числом от 1 до 3650 дней.';
    }

    if (
        strlen($connectionName) > 64
        || ($connectionName !== '' && preg_match('/^[a-zA-Z0-9_.-]+$/D', $connectionName) !== 1)
    ) {
        $errors[] = 'Имя соединения должно содержать не более 64 латинских букв, цифр, точек, дефисов или подчёркиваний.';
    }

    if ($errors === []) {
        Option::set($moduleId, 'retention_days', (string) $retentionDays);
        Option::set($moduleId, 'connection_name', $connectionName);
        Option::set($moduleId, 'enabled', isset($_POST['enabled']) && $_POST['enabled'] === 'Y' ? 'Y' : 'N');
        Option::set($moduleId, 'payloads_enabled', isset($_POST['payloads_enabled']) && $_POST['payloads_enabled'] === 'Y' ? 'Y' : 'N');
        Option::set($moduleId, 'error_location_enabled', isset($_POST['error_location_enabled']) && $_POST['error_location_enabled'] === 'Y' ? 'Y' : 'N');

        \CodeMatrix\IntegrationInspector\Api\Inspector::reset();
        $saved = true;
    }
}

$settings = [
    'retention_days' => Option::get($moduleId, 'retention_days', '30'),
    'connection_name' => Option::get($moduleId, 'connection_name', ''),
    'enabled' => Option::get($moduleId, 'enabled', 'Y'),
    'payloads_enabled' => Option::get($moduleId, 'payloads_enabled', 'N'),
    'error_location_enabled' => Option::get($moduleId, 'error_location_enabled', 'N'),
];

$APPLICATION->SetTitle('Настройки CodeMatrix Integration Inspector');

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($saved) {
    CAdminMessage::ShowNote('Настройки сохранены.');
}

foreach ($errors as $error) {
    CAdminMessage::ShowMessage([
        'TYPE' => 'ERROR',
        'MESSAGE' => 'Не удалось сохранить настройки',
        'DETAILS' => $error,
        'HTML' => true,
    ]);
}

$tabControl = new CAdminTabControl('cmiIntegrationInspectorSettings', [
    ['DIV' => 'settings', 'TAB' => 'Основные настройки', 'TITLE' => 'Параметры журнала интеграций'],
]);

?>
<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>?mid=<?= urlencode($moduleId) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%">Ведение журнала</td>
        <td>
            <input type="checkbox" name="enabled" value="Y"<?= $settings['enabled'] === 'Y' ? ' checked' : '' ?>>
            <span class=" ಕ">Записывать новые события</span>
        </td>
    </tr>
    <tr>
        <td>Срок хранения, дней</td>
        <td>
            <input type="number" name="retention_days" min="1" max="3650" value="<?= htmlspecialcharsbx($settings['retention_days']) ?>" required>
            <span class="adm-info-message">От 1 до 3650 дней. Очистка выполняется ежедневно.</span>
        </td>
    </tr>
    <tr>
        <td>Имя соединения Bitrix</td>
        <td>
            <input type="text" name="connection_name" maxlength="64" value="<?= htmlspecialcharsbx($settings['connection_name']) ?>">
            <p class="adm-info-message">Оставьте пустым для стандартного соединения. Укажите имя уже настроенного соединения Bitrix, чтобы хранить журнал отдельно. Модуль не создаёт и не изменяет подключения или учётные данные.</p>
        </td>
    </tr>
    <tr>
        <td>Сохранять payload запросов и ответов</td>
        <td>
            <input type="checkbox" name="payloads_enabled" value="Y"<?= $settings['payloads_enabled'] === 'Y' ? ' checked' : '' ?>>
            <span>Разрешить запись payload через API</span>
            <p class="adm-info-message">Включайте только если уверены, что данные не содержат паролей, токенов и персональных данных.</p>
        </td>
    </tr>
    <tr>
        <td>Сохранять расположение ошибки</td>
        <td>
            <input type="checkbox" name="error_location_enabled" value="Y"<?= $settings['error_location_enabled'] === 'Y' ? ' checked' : '' ?>>
            <span>Записывать файл и строку возникновения ошибки</span>
        </td>
    </tr>
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <?php $tabControl->End(); ?>
</form>
<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
