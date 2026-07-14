<?php

namespace Tests\Shop\Unit\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Packages\Shop\Repository\ClaimRepository;
use Tests\Shop\TestCase;

final class ClaimRepositoryTest extends TestCase
{
    public function testTransactionPreservesDomainValidationException(): void
    {
        $domainException = new \DomainException('교환 가능 수량을 초과했습니다.');
        $database = $this->createMock(Database::class);
        $database->method('transaction')->willThrowException(
            new DatabaseException('Transaction failed', 0, $domainException)
        );
        $repository = new ClaimRepository($database);

        $this->expectExceptionObject($domainException);
        $repository->transaction(static fn(): null => null);
    }

    public function testTransactionDoesNotHideDatabaseFailure(): void
    {
        $databaseException = new DatabaseException('connection lost');
        $database = $this->createMock(Database::class);
        $database->method('transaction')->willThrowException($databaseException);
        $repository = new ClaimRepository($database);

        $this->expectExceptionObject($databaseException);
        $repository->transaction(static fn(): null => null);
    }
}
