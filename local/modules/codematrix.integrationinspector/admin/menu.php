<?php

declare(strict_types=1);

return [
    'parent_menu' => 'global_menu_services',
    'sort' => 450,
    'text' => 'Журнал интеграций',
    'title' => 'Диагностика и просмотр событий интеграций',
    'url' => 'cmi_integrationinspector_events.php?lang=' . LANGUAGE_ID,
    'more_url' => [
        'cmi_integrationinspector_events.php',
    ],
    'module_id' => 'codematrix.integrationinspector',
    'items_id' => 'menu_codematrix_integrationinspector',
    'items' => [],
];
