<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Пакетная очистка истории по сроку хранения.
 */
final class EventCleanup
{
    private const MODULE_ID = 'codematrix.integrationinspector';
    private const LOCK_NAME = 'cmi_integrationinspector_cleanup';
    private const BATCH_SIZE = 500;
    private const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Агент Bitrix. Возвращает выражение для следующего ежедневного запуска.
     */
    public static function run(): string
    {
        try {
            $connection = Application::getConnection();
            $helper = $connection->getSqlHelper();
            $lockName = $helper->forSql(self::LOCK_NAME);
            $lock = $connection->query("SELECT GET_LOCK('{$lockName}', 0) AS LOCKED")->fetch();

            if ((int) ($lock['LOCKED'] ?? 0) !== 1) {
                return '\\CodeMatrix\\IntegrationInspector\\Storage\\EventCleanup::run();';
            }

            try {
                $retentionDays = (int) Option::get(
                    self::MODULE_ID,
                    'retention_days',
                    (string) self::DEFAULT_RETENTION_DAYS
                );
                $retentionDays = max(1, min(3650, $retentionDays));
                $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->modify('-' . $retentionDays . ' days')
                    ->format('Y-m-d H:i:s');
                $quotedCutoff = "'" . $helper->forSql($cutoff) . "'";

                do {
                    $result = $connection->query(
                        "SELECT EVENT_ID
                         FROM b_cmi_event
                         WHERE DATE_START < {$quotedCutoff}
                         ORDER BY DATE_START, EVENT_ID
                         LIMIT " . self::BATCH_SIZE
                    );
                    $eventIds = [];
                    while ($row = $result->fetch()) {
                        if (is_array($row) && isset($row['EVENT_ID'])) {
                            $eventIds[] = "'" . $helper->forSql((string) $row['EVENT_ID']) . "'";
                        }
                    }

                    $deleted = count($eventIds);
                    if ($deleted === 0) {
                        break;
                    }

                    $idList = implode(', ', $eventIds);
                    $connection->queryExecute(
                        "DELETE FROM b_cmi_event_data WHERE EVENT_ID IN ({$idList})"
                    );
                    $connection->queryExecute(
                        "DELETE FROM b_cmi_event WHERE EVENT_ID IN ({$idList})"
                    );
                } while ($deleted === self::BATCH_SIZE);
            } finally {
                $connection->queryExecute("SELECT RELEASE_LOCK('{$lockName}')");
            }
        } catch (Throwable $exception) {
            AddMessage2Log(
                'Integration Inspector cleanup failed: ' . $exception->getMessage(),
                self::MODULE_ID
            );
        }

        return '\\CodeMatrix\\IntegrationInspector\\Storage\\EventCleanup::run();';
    }
}
