<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage;

use CodeMatrix\IntegrationInspector\Api\FinalizeResult;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

/**
 * MySQL storage adapter built on the Bitrix D7 Connection API.
 *
 * The connection is supplied by the module factory. This class never starts,
 * commits, or rolls back transactions owned by the application.
 */
final class MySqlEventStorage implements EventStorage
{
    private object $connection;
    private object $sqlHelper;

    public function __construct(object $connection)
    {
        foreach (['queryExecute', 'getAffectedRowsCount', 'getSqlHelper'] as $method) {
            if (!method_exists($connection, $method)) {
                throw new \InvalidArgumentException('Connection must provide the Bitrix D7 SQL API.');
            }
        }

        $this->connection = $connection;
        $this->sqlHelper = $connection->getSqlHelper();
    }

    public function createEvent(
        string $eventId,
        string $sourceCode,
        string $operation,
        string $direction,
        ?string $correlationId,
        ?string $parentEventId,
        array $requestMetadata,
        array $context,
        ?string $requestPayload,
        ?DateTimeImmutable $occurredAt,
        DateTimeImmutable $startedAt
    ): bool {
        $sql = "INSERT INTO b_cmi_event (
            EVENT_ID, SOURCE_CODE, OPERATION, DIRECTION, STATUS,
            CORRELATION_ID, PARENT_EVENT_ID, DATE_START, OCCURRED_AT
        ) VALUES (
            {$this->quote($eventId)},
            {$this->quote($sourceCode)},
            {$this->quote($operation)},
            {$this->quote($direction)},
            'pending',
            {$this->quoteNullable($correlationId)},
            {$this->quoteNullable($parentEventId)},
            {$this->quoteDate($startedAt)},
            {$this->quoteDateNullable($occurredAt)}
        )";

        $this->connection->queryExecute($sql);

        if ($requestMetadata !== [] || $context !== [] || $requestPayload !== null) {
            try {
                $this->connection->queryExecute(
                    "INSERT INTO b_cmi_event_data (
                        EVENT_ID, REQUEST_META, CONTEXT, REQUEST_PAYLOAD
                    ) VALUES (
                        {$this->quote($eventId)},
                        {$this->quoteNullable($this->encode($requestMetadata))},
                        {$this->quoteNullable($this->encode($context))},
                        {$this->quoteNullable($requestPayload)}
                    )"
                );
            } catch (Throwable) {
                // Keep the primary event even if optional diagnostic data cannot be stored.
            }
        }

        return true;
    }

    public function finalizeEvent(
        string $eventId,
        string $status,
        array $result,
        int $durationMs,
        DateTimeImmutable $finishedAt
    ): FinalizeResult {
        $this->connection->queryExecute(
            "UPDATE b_cmi_event SET
                STATUS = {$this->quote($status)},
                DATE_FINISH = {$this->quoteDate($finishedAt)},
                DURATION_MS = " . max(0, $durationMs) . ",
                HTTP_STATUS = " . $this->quoteNullableInteger($result['http_status'] ?? null) . ",
                RESULT_CODE = {$this->quoteNullable($result['result_code'] ?? null)},
                ERROR_CLASS = {$this->quoteNullable($result['exception_class'] ?? null)},
                ERROR_CODE = {$this->quoteNullable($result['exception_code'] ?? null)},
                ERROR_MESSAGE = {$this->quoteNullable($result['error_message'] ?? null)},
                ERROR_FILE = {$this->quoteNullable($result['error_file'] ?? null)},
                ERROR_LINE = {$this->quoteNullableInteger($result['error_line'] ?? null)}
             WHERE EVENT_ID = {$this->quote($eventId)} AND STATUS = 'pending'"
        );

        if ($this->connection->getAffectedRowsCount() === 0) {
            return FinalizeResult::ALREADY_FINALIZED;
        }

        $responseMetadata = $result['response_metadata'] ?? [];
        $responsePayload = $result['response_payload'] ?? null;

        if ($responseMetadata !== [] || $responsePayload !== null) {
            try {
                $this->connection->queryExecute(
                    "INSERT INTO b_cmi_event_data (EVENT_ID, RESPONSE_META, RESPONSE_PAYLOAD)
                     VALUES (
                        {$this->quote($eventId)},
                        {$this->quoteNullable($this->encode($responseMetadata))},
                        {$this->quoteNullable($responsePayload)}
                     )
                     ON DUPLICATE KEY UPDATE
                        RESPONSE_META = VALUES(RESPONSE_META),
                        RESPONSE_PAYLOAD = VALUES(RESPONSE_PAYLOAD)"
                );
            } catch (Throwable) {
                // Final event status is more important than optional response details.
            }
        }

        return FinalizeResult::RECORDED;
    }

    private function quote(string $value): string
    {
        return "'" . $this->sqlHelper->forSql($value) . "'";
    }

    private function quoteNullable(?string $value): string
    {
        return $value === null ? 'NULL' : $this->quote($value);
    }

    private function quoteNullableInteger(?int $value): string
    {
        return $value === null ? 'NULL' : (string) $value;
    }

    private function quoteDate(DateTimeImmutable $date): string
    {
        return $this->quote($date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    private function quoteDateNullable(?DateTimeImmutable $date): string
    {
        return $date === null ? 'NULL' : $this->quoteDate($date);
    }

    /**
     * @throws JsonException
     */
    private function encode(array $value): ?string
    {
        if ($value === []) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
