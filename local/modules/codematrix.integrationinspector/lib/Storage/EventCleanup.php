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
                    $connection->queryExecute(
                        "DELETE e, d
                         FROM b_cmi_event e
                         LEFT JOIN b_cmi_event_data d ON d.EVENT_ID = e.EVENT_ID
                         INNER JOIN (
                            SELECT EVENT_ID
                            FROM b_cmi_event
                            WHERE DATE_START < {$quotedCutoff}
                            ORDER BY DATE_START, EVENT_ID
                            LIMIT " . self::BATCH_SIZE . "
                         ) expired ON expired.EVENT_ID = e.EVENT_ID"
                    );
                    $deleted = (int) $connection->getAffectedRowsCount();
                } while ($deleted > 0);
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
