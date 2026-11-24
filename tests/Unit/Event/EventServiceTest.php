<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Event;

use CodeMatrix\IntegrationInspector\Api\EventDirection;
use CodeMatrix\IntegrationInspector\Api\EventInput;
use CodeMatrix\IntegrationInspector\Api\EventOutcome;
use CodeMatrix\IntegrationInspector\Api\FinalizeResult;
use CodeMatrix\IntegrationInspector\Api\RecordingStatus;
use CodeMatrix\IntegrationInspector\Event\EventIdGenerator;
use CodeMatrix\IntegrationInspector\Event\EventService;
use CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer;
use CodeMatrix\IntegrationInspector\Storage\EventStorage;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EventServiceTest extends TestCase
{
    public function testBeginStoresOnlySanitizedMetadataAndPayloadIsOffByDefault(): void
    {
        $storage = new InMemoryEventStorage();
        $service = new EventService($storage, new Sanitizer(), new EventIdGenerator());

        $handle = $service->begin(new EventInput(
            sourceCode: '1c',
            operation: 'order.export',
            direction: EventDirection::OUTGOING,
            requestMetadata: ['Authorization' => 'Bearer private-token', 'method' => 'POST'],
            requestPayload: '{"secret":"private"}'
        ));

        self::assertSame(RecordingStatus::RECORDED, $handle->getRecordingStatus());
        self::assertSame('[REDACTED]', $storage->created['requestMetadata']['Authorization']);
        self::assertNull($storage->created['requestPayload']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $handle->getEventId()
        );
    }

    public function testSuccessfulFinalizationIsRecordedOnlyOnce(): void
    {
        $storage = new InMemoryEventStorage();
        $service = new EventService($storage, new Sanitizer(), new EventIdGenerator());
        $handle = $service->begin(new EventInput('1c', 'order.export', EventDirection::OUTGOING));

        self::assertSame(FinalizeResult::RECORDED, $handle->succeed(new EventOutcome(httpStatus: 200)));
        self::assertSame(FinalizeResult::ALREADY_FINALIZED, $handle->succeed());
        self::assertSame('success', $storage->finalized['status']);
        self::assertSame(200, $storage->finalized['result']['http_status']);
    }

    public function testFailureMessageIsSanitizedBeforeStorage(): void
    {
        $storage = new InMemoryEventStorage();
        $service = new EventService($storage, new Sanitizer(), new EventIdGenerator());
        $handle = $service->begin(new EventInput('1c', 'order.export', EventDirection::OUTGOING));

        self::assertSame(
            FinalizeResult::RECORDED,
            $handle->fail(new RuntimeException('Authorization: Bearer hidden-token'))
        );
        self::assertStringNotContainsString('hidden-token', $storage->finalized['result']['error_message']);
        self::assertArrayNotHasKey('error_file', $storage->finalized['result']);
        self::assertArrayNotHasKey('error_line', $storage->finalized['result']);
    }

    public function testDisabledServiceReturnsNoOpHandleWithoutCallingStorage(): void
    {
        $storage = new InMemoryEventStorage();
        $service = new EventService($storage, new Sanitizer(), new EventIdGenerator(), false);

        $handle = $service->begin(new EventInput('1c', 'order.export', EventDirection::OUTGOING));

        self::assertSame(RecordingStatus::DISABLED, $handle->getRecordingStatus());
        self::assertNull($storage->created);
    }

    public function testStorageFailureDoesNotEscapeIntoBusinessCode(): void
    {
        $storage = new InMemoryEventStorage();
        $storage->failCreate = true;
        $service = new EventService($storage, new Sanitizer(), new EventIdGenerator());

        $handle = $service->begin(new EventInput('1c', 'order.export', EventDirection::OUTGOING));

        self::assertSame(RecordingStatus::STORAGE_UNAVAILABLE, $handle->getRecordingStatus());
        self::assertSame(FinalizeResult::NOT_RECORDED, $handle->succeed());
    }
}

final class InMemoryEventStorage implements EventStorage
{
    public ?array $created = null;
    public ?array $finalized = null;
    public bool $failCreate = false;

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
        if ($this->failCreate) {
            throw new RuntimeException('Storage unavailable');
        }

        $this->created = compact(
            'eventId',
            'sourceCode',
            'operation',
            'direction',
            'correlationId',
            'parentEventId',
            'requestMetadata',
            'context',
            'requestPayload',
            'occurredAt',
            'startedAt'
        );

        return true;
    }

    public function finalizeEvent(
        string $eventId,
        string $status,
        array $result,
        int $durationMs,
        DateTimeImmutable $finishedAt
    ): FinalizeResult {
        $this->finalized = compact('eventId', 'status', 'result', 'durationMs', 'finishedAt');

        return FinalizeResult::RECORDED;
    }
}
