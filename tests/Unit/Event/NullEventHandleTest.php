<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Event;

use CodeMatrix\IntegrationInspector\Api\EventOutcome;
use CodeMatrix\IntegrationInspector\Api\FinalizeResult;
use CodeMatrix\IntegrationInspector\Api\RecordingStatus;
use CodeMatrix\IntegrationInspector\Event\Handle\NullEventHandle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NullEventHandleTest extends TestCase
{
    public function testNoOpHandleNeverThrowsAndReportsNotRecorded(): void
    {
        $handle = new NullEventHandle(RecordingStatus::STORAGE_UNAVAILABLE);

        self::assertNull($handle->getEventId());
        self::assertSame(RecordingStatus::STORAGE_UNAVAILABLE, $handle->getRecordingStatus());
        self::assertSame(FinalizeResult::NOT_RECORDED, $handle->succeed(new EventOutcome()));
        self::assertSame(FinalizeResult::NOT_RECORDED, $handle->fail(new RuntimeException('business error')));
    }
}
