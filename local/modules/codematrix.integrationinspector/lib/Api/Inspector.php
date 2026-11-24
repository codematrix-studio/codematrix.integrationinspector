<?php

declare(strict_types=1);

namespace CodeMatrix\\IntegrationInspector\\Api;

use Bitrix\\Main\\Application;
use Bitrix\\Main\\Config\\Option;
use CodeMatrix\\IntegrationInspector\\Event\\EventIdGenerator;
use CodeMatrix\\IntegrationInspector\\Event\\EventService;
use CodeMatrix\\IntegrationInspector\\Event\\Handle\\NullEventHandle;
use CodeMatrix\\IntegrationInspector\\Sanitizer\\Sanitizer;
use CodeMatrix\\IntegrationInspector\\Storage\\MySqlEventStorage;
use Throwable;

/**
 * Public entry point for recording integration events.
 *
 * The module must be installed and included before calling this facade.
 */
final class Inspector
{
    private const MODULE_ID = 'codematrix.integrationinspector';

    private static ?EventService $service = null;

    private function __construct()
    {
    }

    /**
     * @param array{
     *     direction?: string,
     *     correlationId?: ?string,
     *     parentEventId?: ?string,
     *     requestMetadata?: array,
     *     requestPayload?: ?string,
     *     context?: array,
     *     occurredAt?: ?\\DateTimeImmutable
     * } $options
     */
    public static function begin(string $sourceCode, string $operation, array $options = []): EventHandle
    {
        try {
            $direction = EventDirection::from($options['direction'] ?? EventDirection::OUTGOING->value);
            $input = new EventInput(
                $sourceCode,
                $operation,
                $direction,
                $options['correlationId'] ?? null,
                $options['parentEventId'] ?? null,
                $options['requestMetadata'] ?? [],
                $options['requestPayload'] ?? null,
                $options['context'] ?? [],
                $options['occurredAt'] ?? null
            );

            return self::service()->begin($input);
        } catch (\\InvalidArgumentException) {
            return new NullEventHandle(RecordingStatus::INVALID_INPUT);
        } catch (Throwable) {
            return new NullEventHandle(RecordingStatus::STORAGE_UNAVAILABLE);
        }
    }

    /**
     * Reset the cached service after changing module options in the admin area.
     */
    public static function reset(): void
    {
        self::$service = null;
    }

    private static function service(): EventService
    {
        if (self::$service !== null) {
            return self::$service;
        }

        $enabled = Option::get(self::MODULE_ID, 'enabled', 'Y') === 'Y';
        $payloadsEnabled = Option::get(self::MODULE_ID, 'payloads_enabled', 'N') === 'Y';
        $errorLocationEnabled = Option::get(self::MODULE_ID, 'error_location_enabled', 'N') === 'Y';

        self::$service = new EventService(
            new MySqlEventStorage(Application::getConnection()),
            new Sanitizer(),
            new EventIdGenerator(),
            $enabled,
            $payloadsEnabled,
            $errorLocationEnabled
        );

        return self::$service;
    }
}
