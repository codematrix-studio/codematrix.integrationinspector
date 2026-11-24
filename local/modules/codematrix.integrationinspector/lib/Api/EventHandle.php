<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Api;

interface EventHandle
{
    public function getEventId(): ?string;

    public function getRecordingStatus(): RecordingStatus;

    public function succeed(?EventOutcome $outcome = null): FinalizeResult;

    public function fail(\Throwable $exception): FinalizeResult;
}
