<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Storage\Migrations;

use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlMigrationLock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlMigrationLockTest extends TestCase
{
    public function testAcquiresAndReleasesNamedLock(): void
    {
        $connection = new FakeAdvisoryLockConnection([
            ['LOCK_RESULT' => '1'],
            ['LOCK_RESULT' => '1'],
        ]);
        $lock = new MySqlMigrationLock($connection);

        self::assertTrue($lock->acquire());
        $lock->release();

        self::assertCount(2, $connection->queries);
        self::assertStringContainsString('GET_LOCK(', $connection->queries[0]);
        self::assertStringContainsString('RELEASE_LOCK(', $connection->queries[1]);
    }

    public function testReturnsFalseWhenAnotherProcessHoldsLock(): void
    {
        $connection = new FakeAdvisoryLockConnection([['LOCK_RESULT' => '0']]);
        $lock = new MySqlMigrationLock($connection);

        self::assertFalse($lock->acquire());
        $lock->release();

        self::assertCount(1, $connection->queries);
    }

    public function testLostLockOwnershipDoesNotLeaveTheInstanceMarkedAsAcquired(): void
    {
        $connection = new FakeAdvisoryLockConnection([
            ['LOCK_RESULT' => '1'],
            ['LOCK_RESULT' => null],
            ['LOCK_RESULT' => '1'],
        ]);
        $lock = new MySqlMigrationLock($connection);

        self::assertTrue($lock->acquire());

        try {
            $lock->release();
            self::fail('Expected release failure after lock ownership was lost.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to release MySQL schema migration lock.', $exception->getMessage());
        }

        // Повторный acquire должен выполнить новый GET_LOCK(), а не вернуть
        // локальный флаг от предыдущего соединения.
        self::assertTrue($lock->acquire());
        self::assertCount(3, $connection->queries);
        self::assertStringContainsString('GET_LOCK(', $connection->queries[2]);
    }

    public function testRejectsOverlongLockName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MySqlMigrationLock(new FakeAdvisoryLockConnection([]), str_repeat('x', 65));
    }

    public function testNullLockResultIsAnError(): void
    {
        $lock = new MySqlMigrationLock(
            new FakeAdvisoryLockConnection([['LOCK_RESULT' => null]])
        );

        $this->expectException(RuntimeException::class);
        $lock->acquire();
    }
}

final class FakeAdvisoryLockConnection
{
    public array $queries = [];

    public function __construct(private array $rows)
    {
    }

    public function query(string $sql): FakeAdvisoryLockResult
    {
        $this->queries[] = $sql;

        return new FakeAdvisoryLockResult(array_shift($this->rows));
    }
}

final class FakeAdvisoryLockResult
{
    public function __construct(private mixed $row)
    {
    }

    public function fetch(): mixed
    {
        return $this->row;
    }
}
