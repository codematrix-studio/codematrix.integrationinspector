<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Event\Handle;

use CodeMatrix\IntegrationInspector\Api\EventHandle;
use CodeMatrix\IntegrationInspector\Api\EventOutcome;
use CodeMatrix\IntegrationInspector\Api\FinalizeResult;
use CodeMatrix\IntegrationInspector\Api\RecordingStatus;
use CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer;
use CodeMatrix\IntegrationInspector\Storage\EventStorage;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class StoredEventHandle implements EventHandle
{
    private bool $finalized = false;

    public function __construct(
        private readonly string $eventId,
        private readonly int $startedAtNanoseconds,
        private readonly EventStorage $storage,
        private readonly Sanitizer $sanitizer,
        private readonly bool $payloadsEnabled,
        private readonly bool $errorLocationEnabled = false
    ) {
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function getRecordingStatus(): RecordingStatus
    {
        return RecordingStatus::RECORDED;
    }

    public function succeed(?EventOutcome $outcome = null): FinalizeResult
    {
        try {
            $outcome ??= new EventOutcome();

            return $this->finalize('success', [
                'http_status' => $outcome->httpStatus,
                'result_code' => $outcome->resultCode,
                'response_metadata' => $this->sanitizer->sanitizeMap(
                    $outcome->responseMetadata,
                    Sanitizer::RESPONSE_METADATA_LIMIT
                ),
                'response_payload' => $this->sanitizer->sanitizePayload(
                    $outcome->responsePayload,
                    $this->payloadsEnabled,
                    $outcome->responsePayload !== null
                ),
            ]);
        } catch (Throwable) {
            return FinalizeResult::STORAGE_UNAVAILABLE;
        }
    }

    public function fail(Throwable $exception): FinalizeResult
    {
        try {
            $error = [
                'exception_class' => get_class($exception),
                'exception_code' => (string) $exception->getCode(),
                'error_message' => $this->sanitizer->sanitizeErrorMessage($exception->getMessage()),
            ];

            if ($this->errorLocationEnabled) {
                $error['error_file'] = basename($exception->getFile());
                $error['error_line'] = $exception->getLine();
            }

            return $this->finalize('error', $error);
        } catch (Throwable) {
            return FinalizeResult::STORAGE_UNAVAILABLE;
        }
    }

    private function finalize(string $status, array $result): FinalizeResult
    {
        if ($this->finalized) {
            return FinalizeResult::ALREADY_FINALIZED;
        }

        try {
            $durationMs = (int) ((hrtime(true) - $this->startedAtNanoseconds) / 1_000_000);
            $finishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $finalizeResult = $this->storage->finalizeEvent(
                $this->eventId,
                $status,
                $result,
                max(0, $durationMs),
                $finishedAt
            );

            if ($finalizeResult !== FinalizeResult::STORAGE_UNAVAILABLE) {
                $this->finalized = true;
            }

            return $finalizeResult;
        } catch (Throwable) {
            return FinalizeResult::STORAGE_UNAVAILABLE;
        }
    }
}
