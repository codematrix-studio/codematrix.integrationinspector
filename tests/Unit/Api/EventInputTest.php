<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Api;

use CodeMatrix\IntegrationInspector\Api\EventDirection;
use CodeMatrix\IntegrationInspector\Api\EventInput;
use PHPUnit\Framework\TestCase;

final class EventInputTest extends TestCase
{
    public function testAcceptsValidInput(): void
    {
        $input = new EventInput(
            sourceCode: '1c',
            operation: 'order.export',
            direction: EventDirection::OUTGOING,
            correlationId: 'order-42',
        );

        self::assertSame('1c', $input->sourceCode);
        self::assertSame(EventDirection::OUTGOING, $input->direction);
    }

    public function testRejectsInvalidSourceCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventInput(
            sourceCode: 'Invalid Source',
            operation: 'order.export',
            direction: EventDirection::OUTGOING,
        );
    }

    public function testRejectsInvalidParentEventId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventInput(
            sourceCode: '1c',
            operation: 'order.export',
            direction: EventDirection::OUTGOING,
            parentEventId: 'not-a-uuid',
        );
    }
}
