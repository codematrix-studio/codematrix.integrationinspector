<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Admin;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Чтение журнала событий для административного интерфейса.
 *
 * Класс не изменяет данные и не управляет транзакциями Bitrix.
 */
final class EventRepository
{
    private object $connection;
    private object $sqlHelper;

    public function __construct(object $connection)
    {
        if (!method_exists($connection, 'query') || !method_exists($connection, 'getSqlHelper')) {
            throw new InvalidArgumentException('Connection must provide query() and getSqlHelper().');
        }

        $this->connection = $connection;
        $this->sqlHelper = $connection->getSqlHelper();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findEvents(array $filters, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $where = $this->buildWhere($filters);

        $result = $this->connection->query(
            'SELECT EVENT_ID, SOURCE_CODE, OPERATION, DIRECTION, STATUS, '
            . 'CORRELATION_ID, DATE_START, DATE_FINISH, DURATION_MS, HTTP_STATUS, '
            . 'RESULT_CODE, ERROR_CLASS, ERROR_MESSAGE '
            . 'FROM b_cmi_event'
            . $where
            . ' ORDER BY DATE_START DESC, EVENT_ID DESC'
            . ' LIMIT ' . $offset . ', ' . $limit
        );

        return $result->fetchAll();
    }

    public function countEvents(array $filters): int
    {
        $result = $this->connection->query(
            'SELECT COUNT(*) AS CNT FROM b_cmi_event' . $this->buildWhere($filters)
        );
        $row = $result->fetch();

        return (int) ($row['CNT'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEvent(string $eventId): ?array
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $eventId)) {
            return null;
        }

        $result = $this->connection->query(
            'SELECT e.*, d.REQUEST_META, d.RESPONSE_META, d.CONTEXT, '
            . 'd.REQUEST_PAYLOAD, d.RESPONSE_PAYLOAD, d.ERROR_TRACE '
            . 'FROM b_cmi_event e '
            . 'LEFT JOIN b_cmi_event_data d ON d.EVENT_ID = e.EVENT_ID '
            . 'WHERE e.EVENT_ID = ' . $this->quote($eventId)
            . ' LIMIT 1'
        );

        $row = $result->fetch();

        return is_array($row) ? $row : null;
    }

    private function buildWhere(array $filters): string
    {
        $conditions = [];

        $sourceCode = trim((string) ($filters['source_code'] ?? ''));
        if ($sourceCode !== '') {
            $conditions[] = 'SOURCE_CODE = ' . $this->quote(mb_substr($sourceCode, 0, 64));
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['pending', 'success', 'error'], true)) {
            $conditions[] = 'STATUS = ' . $this->quote($status);
        }

        $direction = (string) ($filters['direction'] ?? '');
        if (in_array($direction, ['incoming', 'outgoing'], true)) {
            $conditions[] = 'DIRECTION = ' . $this->quote($direction);
        }

        $correlationId = trim((string) ($filters['correlation_id'] ?? ''));
        if ($correlationId !== '') {
            $conditions[] = 'CORRELATION_ID = ' . $this->quote(mb_substr($correlationId, 0, 128));
        }

        $dateFrom = $this->validDate((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== null) {
            $conditions[] = 'DATE_START >= ' . $this->quote($dateFrom . ' 00:00:00');
        }

        $dateTo = $this->validDate((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== null) {
            $nextDay = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');
            $conditions[] = 'DATE_START < ' . $this->quote($nextDay . ' 00:00:00');
        }

        return $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    }

    private function validDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }

    private function quote(string $value): string
    {
        return "'" . $this->sqlHelper->forSql($value) . "'";
    }
}
