<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Storage;

use CodeMatrix\IntegrationInspector\Api\EventInput;
use DateTimeImmutable;

interface EventStorage
{
    public function createEvent(
        string $eventId,
        EventInput $input,
        array $requestMetadata,
        array $context,
        ?string $requestPayload,
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
