<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Event\Handle;

use CodeMatrix\IntegrationInspector\Api\EventHandle;
use CodeMatrix\IntegrationInspector\Api\EventOutcome;
use CodeMatrix\IntegrationInspector\Api\FinalizeResult;
use CodeMatrix\IntegrationInspector\Api\RecordingStatus;

final readonly class NullEventHandle implements EventHandle
{
    public function __construct(
        private RecordingStatus $recordingStatus
    ) {
    }

    public function getEventId(): ?string
    {
        return null;
    }

    public function getRecordingStatus(): RecordingStatus
    {
        return $this->recordingStatus;
    }

    public function succeed(?EventOutcome $outcome = null): FinalizeResult
    {
        return FinalizeResult::NOT_RECORDED;
    }

    public function fail(\Throwable $exception): FinalizeResult
    {
        return FinalizeResult::NOT_RECORDED;
    }
}
