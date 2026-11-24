<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use CodeMatrix\IntegrationInspector\Admin\EventRepository;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $APPLICATION, $USER;

$moduleId = 'codematrix.integrationinspector';
$APPLICATION->SetTitle('Журнал интеграций');

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ к журналу интеграций разрешён только администраторам.');
    return;
}

if (!Loader::includeModule($moduleId)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    echo '<div class="adm-info-message adm-info-message-red">Не удалось подключить CodeMatrix Integration Inspector.</div>';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

function cmiIntegrationInspectorHtml($value): string
{
    return \CodeMatrix\IntegrationInspector\Admin\HtmlEscaper::escape($value);
}

function cmiIntegrationInspectorGetString(string $name): string
{
    $value = $_GET[$name] ?? '';

    return is_string($value) ? $value : '';
}

function cmiIntegrationInspectorGetPage(): int
{
    $value = cmiIntegrationInspectorGetString('page');

    if ($value === '' || !ctype_digit($value)) {
        return 1;
    }

    return max(1, min((int) $value, 1000000));
}

function cmiIntegrationInspectorDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return '';
    }

    return $date->format('Y-m-d') === $value ? $value : '';
}

$filters = [
    'source_code' => trim(cmiIntegrationInspectorGetString('source_code')),
    'status' => cmiIntegrationInspectorGetString('status'),
    'direction' => cmiIntegrationInspectorGetString('direction'),
    'correlation_id' => trim(cmiIntegrationInspectorGetString('correlation_id')),
    'date_from' => cmiIntegrationInspectorDate(cmiIntegrationInspectorGetString('date_from')),
    'date_to' => cmiIntegrationInspectorDate(cmiIntegrationInspectorGetString('date_to')),
];

$filters['source_code'] = mb_substr($filters['source_code'], 0, 64);
$filters['correlation_id'] = mb_substr($filters['correlation_id'], 0, 128);
if (!in_array($filters['status'], ['', 'pending', 'success', 'error'], true)) {
    $filters['status'] = '';
}
if (!in_array($filters['direction'], ['', 'incoming', 'outgoing'], true)) {
    $filters['direction'] = '';
}

$pageNumber = cmiIntegrationInspectorGetPage();
$pageSize = 50;
$eventId = mb_substr(cmiIntegrationInspectorGetString('event_id'), 0, 36);
$connectionName = trim(Option::get($moduleId, 'connection_name', ''));

try {
    $connection = $connectionName === ''
        ? Application::getConnection()
        : Application::getConnection($connectionName);

    if ($connection === null) {
        throw new RuntimeException('Не удалось получить соединение хранилища.');
    }

    $repository = new EventRepository($connection);

    if (cmiIntegrationInspectorGetString('export') === 'csv') {
        $exportLimit = 10000;
        $exported = 0;
        $filename = 'integration-events-' . gmdate('Y-m-d-His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Не удалось открыть поток CSV.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'Event ID', 'Дата UTC', 'Источник', 'Операция', 'Направление',
            'Статус', 'Correlation ID', 'Длительность, мс', 'HTTP-статус',
            'Код результата', 'Класс ошибки', 'Сообщение ошибки',
        ], ';', '"', '');

        while ($exported < $exportLimit) {
            $batch = $repository->findEvents(
                $filters,
                min(100, $exportLimit - $exported),
                $exported
            );

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $values = [
                    $row['EVENT_ID'] ?? '',
                    $row['DATE_START'] ?? '',
                    $row['SOURCE_CODE'] ?? '',
                    $row['OPERATION'] ?? '',
                    $row['DIRECTION'] ?? '',
                    $row['STATUS'] ?? '',
                    $row['CORRELATION_ID'] ?? '',
                    $row['DURATION_MS'] ?? '',
                    $row['HTTP_STATUS'] ?? '',
                    $row['RESULT_CODE'] ?? '',
                    $row['ERROR_CLASS'] ?? '',
                    $row['ERROR_MESSAGE'] ?? '',
                ];

                $values = array_map(static function ($value): string {
                    $value = (string) $value;

                    return preg_match('/^[\\s]*[=+\\-@]/u', $value) === 1
                        ? "'" . $value
                        : $value;
                }, $values);

                fputcsv($output, $values, ';', '"', '');
                ++$exported;
            }

            if (count($batch) < 100) {
                break;
            }
        }

        fclose($output);
        exit;
    }

    $event = $eventId !== '' ? $repository->findEvent($eventId) : null;
    $total = $eventId === '' ? $repository->countEvents($filters) : 0;
    $pages = max(1, (int) ceil($total / $pageSize));
    $pageNumber = min($pageNumber, $pages);
    $events = $eventId === ''
        ? $repository->findEvents($filters, $pageSize, ($pageNumber - 1) * $pageSize)
        : [];
} catch (Throwable $exception) {
    \CodeMatrix\IntegrationInspector\Diagnostic\DiagnosticLogger::log(
        'admin.events',
        $exception,
        new \CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer()
    );
    $event = null;
    $events = [];
    $total = 0;
    $pages = 1;
    $errorMessage = 'Не удалось прочитать журнал. Подробности доступны в системном PHP error log.';
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (isset($errorMessage)) {
    echo '<div class="adm-info-message adm-info-message-red">' . cmiIntegrationInspectorHtml($errorMessage) . '</div>';
} elseif ($eventId !== '') {
    if ($event === null) {
        echo '<div class="adm-info-message adm-info-message-red">Событие не найдено.</div>';
    } else {
        $backUrl = '/bitrix/admin/cmi_integrationinspector_events.php?lang=' . LANGUAGE_ID;
        echo '<p><a href="' . cmiIntegrationInspectorHtml($backUrl) . '">&larr; К журналу событий</a></p>';
        echo '<table class="adm-detail-content-table edit-table" style="width:100%">';
        $fields = [
            'Идентификатор' => $event['EVENT_ID'] ?? '',
            'Источник' => $event['SOURCE_CODE'] ?? '',
            'Операция' => $event['OPERATION'] ?? '',
            'Направление' => $event['DIRECTION'] ?? '',
            'Статус' => $event['STATUS'] ?? '',
            'Начало (UTC)' => $event['DATE_START'] ?? '',
            'Завершение (UTC)' => $event['DATE_FINISH'] ?? '',
            'Длительность, мс' => $event['DURATION_MS'] ?? '',
            'HTTP-статус' => $event['HTTP_STATUS'] ?? '',
            'Код результата' => $event['RESULT_CODE'] ?? '',
            'Correlation ID' => $event['CORRELATION_ID'] ?? '',
            'Parent event ID' => $event['PARENT_EVENT_ID'] ?? '',
            'Класс ошибки' => $event['ERROR_CLASS'] ?? '',
            'Код ошибки' => $event['ERROR_CODE'] ?? '',
            'Сообщение ошибки' => $event['ERROR_MESSAGE'] ?? '',
            'Файл ошибки' => $event['ERROR_FILE'] ?? '',
            'Строка ошибки' => $event['ERROR_LINE'] ?? '',
        ];

        foreach ($fields as $label => $value) {
            echo '<tr><td style="width:220px"><b>' . cmiIntegrationInspectorHtml($label) . '</b></td><td>'
                . cmiIntegrationInspectorHtml($value) . '</td></tr>';
        }
        echo '</table>';

        $dataFields = [
            'Метаданные запроса' => 'REQUEST_META',
            'Метаданные ответа' => 'RESPONSE_META',
            'Контекст' => 'CONTEXT',
            'Payload запроса' => 'REQUEST_PAYLOAD',
            'Payload ответа' => 'RESPONSE_PAYLOAD',
            'Стек ошибки' => 'ERROR_TRACE',
        ];

        foreach ($dataFields as $label => $key) {
            if (($event[$key] ?? null) !== null && $event[$key] !== '') {
                echo '<h3>' . cmiIntegrationInspectorHtml($label) . '</h3>';
                echo '<pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:420px;overflow:auto">'
                    . cmiIntegrationInspectorHtml($event[$key]) . '</pre>';
            }
        }
    }
} else {
    $baseParams = ['lang' => LANGUAGE_ID];
    foreach ($filters as $key => $value) {
        if ($value !== '') {
            $baseParams[$key] = $value;
        }
    }

    echo '<form method="get" action="/bitrix/admin/cmi_integrationinspector_events.php" class="adm-filter-form">';
    echo '<input type="hidden" name="lang" value="' . cmiIntegrationInspectorHtml(LANGUAGE_ID) . '">';
    echo '<table class="adm-filter-table"><tr>';
    echo '<td><label>Источник<br><input type="text" name="source_code" maxlength="64" value="'
        . cmiIntegrationInspectorHtml($filters['source_code']) . '"></label></td>';
    echo '<td><label>Статус<br><select name="status">';
    foreach (['' => 'Все', 'pending' => 'В процессе', 'success' => 'Успешно', 'error' => 'Ошибка'] as $value => $label) {
        echo '<option value="' . cmiIntegrationInspectorHtml($value) . '"'
            . ($filters['status'] === $value ? ' selected' : '') . '>'
            . cmiIntegrationInspectorHtml($label) . '</option>';
    }
    echo '</select></label></td>';
    echo '<td><label>Направление<br><select name="direction">';
    foreach (['' => 'Все', 'incoming' => 'Входящее', 'outgoing' => 'Исходящее'] as $value => $label) {
        echo '<option value="' . cmiIntegrationInspectorHtml($value) . '"'
            . ($filters['direction'] === $value ? ' selected' : '') . '>'
            . cmiIntegrationInspectorHtml($label) . '</option>';
    }
    echo '</select></label></td>';
    echo '<td><label>Дата с<br><input type="date" name="date_from" value="'
        . cmiIntegrationInspectorHtml($filters['date_from']) . '"></label></td>';
    echo '<td><label>Дата по<br><input type="date" name="date_to" value="'
        . cmiIntegrationInspectorHtml($filters['date_to']) . '"></label></td>';
    echo '<td><label>Correlation ID<br><input type="text" name="correlation_id" maxlength="128" value="'
        . cmiIntegrationInspectorHtml($filters['correlation_id']) . '"></label></td>';
    echo '<td><br><input type="submit" class="adm-btn-save" value="Применить"> ';
    echo '<a class="adm-btn" href="/bitrix/admin/cmi_integrationinspector_events.php?lang='
        . cmiIntegrationInspectorHtml(LANGUAGE_ID) . '">Сбросить</a></td>';
    echo '</tr></table></form>';

    $exportUrl = '/bitrix/admin/cmi_integrationinspector_events.php?' . http_build_query(
        $baseParams + ['export' => 'csv']
    );
    echo '<div class="adm-list-table-top"><b>Найдено событий: ' . (int) $total . '</b> ';
    echo '<a class="adm-btn" href="' . cmiIntegrationInspectorHtml($exportUrl) . '">Экспорт CSV (до 10 000)</a></div>';
    echo '<table class="adm-list-table" style="width:100%"><thead><tr>';
    foreach (['Дата (UTC)', 'Источник', 'Операция', 'Направление', 'Статус', 'HTTP', 'Длительность'] as $heading) {
        echo '<th class="adm-list-table-cell"><span class="adm-list-table-cell-inner">'
            . cmiIntegrationInspectorHtml($heading) . '</span></th>';
    }
    echo '</tr></thead><tbody>';

    if ($events === []) {
        echo '<tr><td colspan="7" class="adm-list-table-cell">События не найдены.</td></tr>';
    }

    foreach ($events as $row) {
        $url = '/bitrix/admin/cmi_integrationinspector_events.php?' . http_build_query(
            $baseParams + ['event_id' => (string) $row['EVENT_ID']]
        );
        echo '<tr class="adm-list-table-row">';
        echo '<td class="adm-list-table-cell"><a href="' . cmiIntegrationInspectorHtml($url) . '">'
            . cmiIntegrationInspectorHtml($row['DATE_START'] ?? '') . '</a></td>';
        echo '<td class="adm-list-table-cell">' . cmiIntegrationInspectorHtml($row['SOURCE_CODE'] ?? '') . '</td>';
        echo '<td class="adm-list-table-cell">' . cmiIntegrationInspectorHtml($row['OPERATION'] ?? '') . '</td>';
        echo '<td class="adm-list-table-cell">' . cmiIntegrationInspectorHtml($row['DIRECTION'] ?? '') . '</td>';
        echo '<td class="adm-list-table-cell">' . cmiIntegrationInspectorHtml($row['STATUS'] ?? '') . '</td>';
        echo '<td class="adm-list-table-cell">' . cmiIntegrationInspectorHtml($row['HTTP_STATUS'] ?? '') . '</td>';
        echo '<td class="adm-list-table-cell">' . cmiIntegrationInspectorHtml($row['DURATION_MS'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    if ($pages > 1) {
        $startPage = max(1, $pageNumber - 2);
        $endPage = min($pages, $pageNumber + 2);
        $visiblePages = array_unique(array_merge(
            [1],
            range($startPage, $endPage),
            [$pages]
        ));

        echo '<div class="adm-nav-pages"><span>Страницы: </span>';
        $previousPage = 0;
        foreach ($visiblePages as $number) {
            if ($previousPage > 0 && $number > $previousPage + 1) {
                echo '… ';
            }

            $url = '/bitrix/admin/cmi_integrationinspector_events.php?' . http_build_query(
                $baseParams + ['page' => $number]
            );
            if ($number === $pageNumber) {
                echo '<b>' . $number . '</b> ';
            } else {
                echo '<a href="' . cmiIntegrationInspectorHtml($url) . '">' . $number . '</a> ';
            }
            $previousPage = $number;
        }
        echo '</div>';
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
