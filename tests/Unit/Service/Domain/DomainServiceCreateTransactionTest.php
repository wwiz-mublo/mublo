<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Core\Event\Domain\DomainCreatedEvent;
use Mublo\Core\Event\Domain\DomainProvisioningEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Entity\Member\Member;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainService;
use Mublo\Service\Member\FieldEncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DomainServiceCreateTransactionTest extends TestCase
{
    private DomainRepository&MockObject $domainRepository;
    private MemberRepository&MockObject $memberRepository;
    private Database&MockObject $database;
    private EventDispatcher $eventDispatcher;
    private Member $ownerCandidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->memberRepository = $this->createMock(MemberRepository::class);
        $this->database = $this->createMock(Database::class);
        $this->eventDispatcher = new EventDispatcher();

        $member = $this->createMock(Member::class);
        $member->method('getMemberId')->willReturn(5);
        $member->method('getDomainId')->willReturn(1);
        $member->method('getUserId')->willReturn('owner1');
        $member->method('canOperateDomain')->willReturn(true);
        $member->method('getDomainGroup')->willReturn('1');
        $member->method('getLevelName')->willReturn('운영자');
        $member->method('getLevelType')->willReturn('admin');
        $this->ownerCandidate = $member;

        $this->memberRepository->method('find')->with(5)->willReturnCallback(
            fn (): Member => $this->ownerCandidate
        );
        $this->domainRepository->method('getDb')->willReturn($this->database);
        $this->domainRepository->method('existsByDomain')->willReturn(false);
    }

    private function makeService(): DomainService
    {
        return new DomainService(
            $this->domainRepository,
            $this->createMock(DomainResolver::class),
            $this->memberRepository,
            $this->eventDispatcher,
            $this->createMock(FieldEncryptionService::class)
        );
    }

    /** @return array{domain: string, member_id: int} */
    private function validData(): array
    {
        return ['domain' => 'child.example.com', 'member_id' => 5];
    }

    public function testRequiredProvisioningRunsInsideTransactionAndCreatedEventRunsAfterCommit(): void
    {
        $insideTransaction = false;
        $requiredObservedInside = false;
        $createdObservedAfter = false;

        $this->database->expects($this->once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use (&$insideTransaction): mixed {
                $insideTransaction = true;
                $result = $callback();
                $insideTransaction = false;
                return $result;
            });

        $this->domainRepository->expects($this->once())->method('create')->willReturn(17);
        $this->domainRepository->expects($this->once())->method('update')->willReturn(1);
        $this->memberRepository->expects($this->once())->method('update')->willReturn(1);

        $this->eventDispatcher->addListener(
            DomainProvisioningEvent::class,
            function () use (&$insideTransaction, &$requiredObservedInside): void {
                $requiredObservedInside = $insideTransaction;
            }
        );
        $this->eventDispatcher->addListener(
            DomainCreatedEvent::class,
            function () use (&$insideTransaction, &$createdObservedAfter): void {
                $createdObservedAfter = !$insideTransaction;
            }
        );

        $result = $this->makeService()->create($this->validData(), '1', 1, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(17, $result->get('domain_id'));
        $this->assertSame('1/17', $result->get('domain_group'));
        $this->assertTrue($requiredObservedInside);
        $this->assertTrue($createdObservedAfter);
    }

    public function testRequiredProvisioningFailureAbortsCreationResultAndSkipsCreatedEvent(): void
    {
        $createdEventCalled = false;

        $this->database->expects($this->once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback): mixed {
                try {
                    return $callback();
                } catch (\Throwable $e) {
                    throw new DatabaseException('Transaction failed', 0, $e);
                }
            });

        $this->domainRepository->expects($this->once())->method('create')->willReturn(17);
        $this->domainRepository->expects($this->once())->method('update')->willReturn(1);
        $this->memberRepository->expects($this->once())->method('update')->willReturn(1);

        $this->eventDispatcher->addListener(
            DomainProvisioningEvent::class,
            static function (): void {
                throw new \RuntimeException('menu seeding failed');
            }
        );
        $this->eventDispatcher->addListener(
            DomainCreatedEvent::class,
            function () use (&$createdEventCalled): void {
                $createdEventCalled = true;
            }
        );

        $result = $this->makeService()->create($this->validData(), '1', 1, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('문제가 발생', $result->getMessage());
        $this->assertStringNotContainsString('seeding', $result->getMessage());
        $this->assertFalse($createdEventCalled);
    }

    public function testCreateRevalidatesSubmittedOwnerIdBeforeWriting(): void
    {
        $outsider = $this->createMock(Member::class);
        $outsider->method('getMemberId')->willReturn(5);
        $outsider->method('getDomainId')->willReturn(2);
        $outsider->method('canOperateDomain')->willReturn(true);
        $outsider->method('getDomainGroup')->willReturn('1/2');
        $this->ownerCandidate = $outsider;

        $this->database->expects($this->never())->method('transaction');
        $this->domainRepository->expects($this->never())->method('create');

        $result = $this->makeService()->create($this->validData(), '1', 1, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('현재 사이트 소속', $result->getMessage());
    }
}
