<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Event;

use CodeMatrix\IntegrationInspector\Event\EventIdGenerator;
use PHPUnit\Framework\TestCase;

final class EventIdGeneratorTest extends TestCase
{
    public function testGeneratesUuidV4(): void
    {
        $id = (new EventIdGenerator())->generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    public function testGeneratesUniqueIdentifiers(): void
    {
        $generator = new EventIdGenerator();

        self::assertNotSame($generator->generate(), $generator->generate());
    }
}
