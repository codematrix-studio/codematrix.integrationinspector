<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Api;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use CodeMatrix\IntegrationInspector\Diagnostic\DiagnosticLogger;
use CodeMatrix\IntegrationInspector\Event\EventIdGenerator;
use CodeMatrix\IntegrationInspector\Event\EventService;
use CodeMatrix\IntegrationInspector\Event\Handle\NullEventHandle;
use CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer;
use CodeMatrix\IntegrationInspector\Storage\MySqlEventStorage;
use Throwable;

/**
 * Публичная точка входа для регистрации событий интеграций.
 *
 * Перед вызовом фасада модуль должен быть установлен и подключён.
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
     *     occurredAt?: ?\DateTimeImmutable
     * } $options
     */
    public static function begin(string $sourceCode, string $operation, array $options = []): EventHandle
    {
        try {
            // Отключённая запись не должна даже пытаться подключаться к хранилищу.
            if (Option::get(self::MODULE_ID, 'enabled', 'Y') !== 'Y') {
                return new NullEventHandle(RecordingStatus::DISABLED);
            }

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
        } catch (\ValueError | \InvalidArgumentException) {
            return new NullEventHandle(RecordingStatus::INVALID_INPUT);
        } catch (Throwable $exception) {
            DiagnosticLogger::log('inspector.begin', $exception, new Sanitizer());

            return new NullEventHandle(RecordingStatus::STORAGE_UNAVAILABLE);
        }
    }

    /**
     * Сбрасывает кэш сервиса после изменения настроек модуля.
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
        $connectionName = trim(Option::get(self::MODULE_ID, 'connection_name', ''));

        // Если явно задано именованное соединение, используем только его.
        // При ошибке получения соединения не переключаемся на default,
        // чтобы запись Inspector не попала в транзакцию приложения.
        $connection = $connectionName === ''
            ? Application::getConnection()
            : Application::getConnection($connectionName);

        if ($connection === null) {
            throw new \RuntimeException('Не удалось получить настроенное соединение Inspector.');
        }

        self::$service = new EventService(
            new MySqlEventStorage($connection),
            new Sanitizer(),
            new EventIdGenerator(),
            $enabled,
            $payloadsEnabled,
            $errorLocationEnabled
        );

        return self::$service;
    }
}
