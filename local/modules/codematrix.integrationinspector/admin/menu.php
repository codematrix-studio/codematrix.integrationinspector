<?php

declare(strict_types=1);

return [
    [
        'parent_menu' => 'global_menu_services',
        'sort' => 450,
        'text' => 'Журнал интеграций',
        'title' => 'Диагностика и просмотр событий интеграций',
        'icon' => 'cmi_integrationinspector_menu_icon',
        'page_icon' => 'cmi_integrationinspector_page_icon',
        'url' => 'cmi_integrationinspector_events.php?lang=' . LANGUAGE_ID,
        'more_url' => [
            'cmi_integrationinspector_events.php',
        ],
        'module_id' => 'codematrix.integrationinspector',
        'items_id' => 'menu_codematrix_integrationinspector',
    ],
];
