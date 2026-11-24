<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses(
    'codematrix.integrationinspector',
    [
        CodeMatrix\IntegrationInspector\Api\EventDirection::class => 'lib/Api/EventDirection.php',
        CodeMatrix\IntegrationInspector\Api\RecordingStatus::class => 'lib/Api/RecordingStatus.php',
        CodeMatrix\IntegrationInspector\Api\FinalizeResult::class => 'lib/Api/FinalizeResult.php',
        CodeMatrix\IntegrationInspector\Api\EventHandle::class => 'lib/Api/EventHandle.php',
        CodeMatrix\IntegrationInspector\Api\EventInput::class => 'lib/Api/EventInput.php',
        CodeMatrix\IntegrationInspector\Api\EventOutcome::class => 'lib/Api/EventOutcome.php',
        CodeMatrix\IntegrationInspector\Api\SourceDefinition::class => 'lib/Api/SourceDefinition.php',
        CodeMatrix\IntegrationInspector\Event\EventIdGenerator::class => 'lib/Event/EventIdGenerator.php',
        CodeMatrix\IntegrationInspector\Event\EventService::class => 'lib/Event/EventService.php',
        CodeMatrix\IntegrationInspector\Event\Handle\StoredEventHandle::class => 'lib/Event/Handle/StoredEventHandle.php',
        CodeMatrix\IntegrationInspector\Storage\EventStorage::class => 'lib/Storage/EventStorage.php',
        CodeMatrix\IntegrationInspector\Storage\MySqlEventStorage::class => 'lib/Storage/MySqlEventStorage.php',
        CodeMatrix\IntegrationInspector\Storage\SchemaInstaller::class => 'lib/Storage/SchemaInstaller.php',
        CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer::class => 'lib/Sanitizer/Sanitizer.php',
    ]
);
