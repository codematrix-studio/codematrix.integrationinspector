<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Unit\Admin;

use CodeMatrix\IntegrationInspector\Admin\EventRepository;
use PHPUnit\Framework\TestCase;

final class EventRepositoryTest extends TestCase
{
    public function testFiltersAreValidatedAndValuesAreEscaped(): void
    {
        $connection = new FakeConnection();
        $repository = new EventRepository($connection);

        $repository->findEvents([
            'source_code' => "api' OR 1=1 --",
            'status' => 'unexpected',
            'direction' => 'outgoing',
            'date_from' => '2026-11-24',
            'date_to' => '2026-02-31',
            'correlation_id' => 'trace-123',
        ], 500, -10);

        $sql = $connection->queries[0];

        self::assertStringContainsString("SOURCE_CODE = 'api\\' OR 1=1 --'", $sql);
        self::assertStringNotContainsString('STATUS =', $sql);
        self::assertStringContainsString("DIRECTION = 'outgoing'", $sql);
        self::assertStringContainsString("DATE_START >= '2026-11-24 00:00:00'", $sql);
        self::assertStringNotContainsString('2026-03-03', $sql);
        self::assertStringContainsString("CORRELATION_ID = 'trace-123'", $sql);
        self::assertStringContainsString('LIMIT 0, 100', $sql);
    }

    public function testEventIdMustBeUuid(): void
    {
        $connection = new FakeConnection();
        $repository = new EventRepository($connection);

        self::assertNull($repository->findEvent('not-an-id'));
        self::assertSame([], $connection->queries);
    }
}

final class FakeConnection
{
    /** @var array<int, string> */
    public array $queries = [];

    public function query(string $sql): FakeResult
    {
        $this->queries[] = $sql;

        return new FakeResult();
    }

    public function getSqlHelper(): FakeSqlHelper
    {
        return new FakeSqlHelper();
    }
}

final class FakeSqlHelper
{
    public function forSql(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}

final class FakeResult
{
    public function fetchAll(): array
    {
        return [];
    }

    public function fetch(): array
    {
        return [];
    }
}
