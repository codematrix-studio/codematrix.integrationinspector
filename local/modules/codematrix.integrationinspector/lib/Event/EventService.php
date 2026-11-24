<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Event;

use CodeMatrix\IntegrationInspector\Api\EventInput;
use CodeMatrix\IntegrationInspector\Api\RecordingStatus;
use CodeMatrix\IntegrationInspector\Event\Handle\NullEventHandle;
use CodeMatrix\IntegrationInspector\Event\Handle\StoredEventHandle;
use CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer;
use CodeMatrix\IntegrationInspector\Storage\EventStorage;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class EventService
{
    public function __construct(
        private EventStorage $storage,
        private Sanitizer $sanitizer,
        private EventIdGenerator $idGenerator,
        private bool $enabled = true,
        private bool $payloadsEnabled = false
    ) {
    }

    public function begin(EventInput $input): StoredEventHandle|NullEventHandle
    {
        if (!$this->enabled) {
            return new NullEventHandle(RecordingStatus::DISABLED);
        }

        try {
            $eventId = $this->idGenerator->generate();
            $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $requestMetadata = $this->sanitizer->sanitizeMap(
                $input->requestMetadata,
                Sanitizer::REQUEST_METADATA_LIMIT
            );
            $context = $this->sanitizer->sanitizeMap(
                $input->context,
                Sanitizer::CONTEXT_LIMIT
            );
            $requestPayload = $this->sanitizer->sanitizePayload(
                $input->requestPayload,
                $this->payloadsEnabled,
                $input->requestPayload !== null
            );

            if (!$this->storage->createEvent(
                $eventId,
                $input->sourceCode,
                $input->operation,
                $input->direction->value,
                $input->correlationId,
                $input->parentEventId,
                $requestMetadata,
                $context,
                $requestPayload,
                $input->occurredAt,
                $startedAt
            )) {
                return new NullEventHandle(RecordingStatus::STORAGE_UNAVAILABLE);
            }

            return new StoredEventHandle(
                $eventId,
                hrtime(true),
                $this->storage,
                $this->sanitizer,
                $this->payloadsEnabled
            );
        } catch (Throwable) {
            return new NullEventHandle(RecordingStatus::STORAGE_UNAVAILABLE);
        }
    }
}
