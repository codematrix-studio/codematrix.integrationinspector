<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Storage;

use CodeMatrix\IntegrationInspector\Api\FinalizeResult;
use CodeMatrix\IntegrationInspector\Storage\MySqlEventStorage;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MySqlEventStorageTest extends TestCase
{
    public function testCreateEventUsesEscapedValuesAndStoresOptionalDataSeparately(): void
    {
        $connection = new FakeBitrixConnection();
        $storage = new MySqlEventStorage($connection);

        self::assertTrue($storage->createEvent(
            '11111111-1111-4111-8111-111111111111',
            '1c',
            "order's.export",
            'outgoing',
            null,
            null,
            ['method' => 'POST'],
            ['scope' => 'catalog'],
            null,
            null,
            new DateTimeImmutable('2026-11-24T04:00:00+00:00')
        ));

        self::assertCount(2, $connection->queries);
        self::assertStringContainsString("order\'s.export", $connection->queries[0]);
        self::assertStringContainsString("INSERT INTO b_cmi_event_data", $connection->queries[1]);
        self::assertStringContainsString('"method":"POST"', $connection->queries[1]);
        self::assertStringContainsString('"scope":"catalog"', $connection->queries[1]);
    }

    public function testCreateEventDoesNotInsertEmptyEventDataRow(): void
    {
        $connection = new FakeBitrixConnection();
        $storage = new MySqlEventStorage($connection);

        $storage->createEvent(
            '11111111-1111-4111-8111-111111111111',
            '1c',
            'order.export',
            'outgoing',
            null,
            null,
            [],
            [],
            null,
            null,
            new DateTimeImmutable('2026-11-24T04:00:00+00:00')
        );

        self::assertCount(1, $connection->queries);
    }

    public function testFinalizeUsesConditionalPendingUpdate(): void
    {
        $connection = new FakeBitrixConnection();
        $connection->affectedRows = 1;
        $storage = new MySqlEventStorage($connection);

        $result = $storage->finalizeEvent(
            '11111111-1111-4111-8111-111111111111',
            'success',
            ['http_status' => 200, 'response_metadata' => ['rows' => 3]],
            42,
            new DateTimeImmutable('2026-11-24T04:00:01+00:00')
        );

        self::assertSame(FinalizeResult::RECORDED, $result);
        self::assertCount(2, $connection->queries);
        self::assertStringContainsString("WHERE EVENT_ID =", $connection->queries[0]);
        self::assertStringContainsString("AND STATUS = 'pending'", $connection->queries[0]);
        self::assertStringContainsString("ON DUPLICATE KEY UPDATE", $connection->queries[1]);
    }

    public function testFinalizeReturnsAlreadyFinalizedWhenNoPendingRowWasUpdated(): void
    {
        $connection = new FakeBitrixConnection();
        $connection->affectedRows = 0;
        $storage = new MySqlEventStorage($connection);

        $result = $storage->finalizeEvent(
            '11111111-1111-4111-8111-111111111111',
            'success',
            [],
            1,
            new DateTimeImmutable('2026-11-24T04:00:01+00:00')
        );

        self::assertSame(FinalizeResult::ALREADY_FINALIZED, $result);
        self::assertCount(1, $connection->queries);
    }
}

final class FakeBitrixConnection
{
    public array $queries = [];
    public int $affectedRows = 0;

    public function queryExecute(string $sql): void
    {
        $this->queries[] = $sql;
    }

    public function getAffectedRowsCount(): int
    {
        return $this->affectedRows;
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
        return str_replace("'", "\'", $value);
    }
}
