<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage;

use DateTimeImmutable;

interface EventStorage
{
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
    ): bool;

    public function finalizeEvent(
        string $eventId,
        string $status,
        array $result,
        int $durationMs,
        DateTimeImmutable $finishedAt
    ): bool;
}
