<?php

namespace Tests\Unit\Repository\Notification;

use Mublo\Contract\Notification\MemberNotification;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Repository\Notification\MemberNotificationRepository;
use PHPUnit\Framework\TestCase;

final class MemberNotificationRepositoryTest extends TestCase
{
    public function testSuccessfulInsertIsReportedAsCreated(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('insert')->willReturn(31);
        $repository = new MemberNotificationRepository($db);

        $result = $repository->create($this->notification());

        $this->assertSame(31, $result->notificationId);
        $this->assertTrue($result->created);
    }

    public function testDuplicateKeyRaceReturnsExistingIdAsNotCreated(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('insert')->willThrowException(
            new DatabaseException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')
        );
        $db->expects($this->once())->method('selectOne')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('deduplication_key'),
                    $this->stringContains('FOR UPDATE')
                ),
                [7, 10, 'message:99']
            )
            ->willReturn(['notification_id' => 17]);
        $repository = new MemberNotificationRepository($db);

        $result = $repository->create($this->notification());

        $this->assertSame(17, $result->notificationId);
        $this->assertFalse($result->created);
    }

    public function testNonDuplicateDatabaseFailureIsNotHidden(): void
    {
        $db = $this->createMock(Database::class);
        $failure = new DatabaseException('SQLSTATE[HY000]: server unavailable');
        $db->method('insert')->willThrowException($failure);
        $db->expects($this->never())->method('selectOne');
        $repository = new MemberNotificationRepository($db);

        $this->expectExceptionObject($failure);
        $repository->create($this->notification());
    }

    private function notification(): MemberNotification
    {
        return new MemberNotification(
            domainId: 7,
            memberId: 10,
            type: 'message.received',
            title: '새 쪽지가 도착했습니다.',
            deduplicationKey: 'message:99',
        );
    }
}
