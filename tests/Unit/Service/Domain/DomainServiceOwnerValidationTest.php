<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Core\Event\Domain\DomainOwnerValidatingEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainService;
use Mublo\Service\Member\FieldEncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * 도메인 소유자 검증 회귀 테스트
 *
 * 배경: 코어에 박혀 있던 "1사이트 제한"(상용/임대 정책)을 제거하고
 * DomainOwnerValidatingEvent 확장점으로 대체했다.
 * - 코어는 소유 수를 세지 않는다 (countByMemberId 미호출 검증)
 * - 정책은 패키지가 이벤트 구독으로 얹고, addError() 시 검증이 실패한다
 */
class DomainServiceOwnerValidationTest extends TestCase
{
    private DomainRepository $domainRepository;
    private MemberRepository $memberRepository;
    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->memberRepository = $this->createMock(MemberRepository::class);
        $this->eventDispatcher = new EventDispatcher();
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

    private function stubOwnerCandidate(): void
    {
        $member = $this->createMock(Member::class);
        $member->method('getMemberId')->willReturn(5);
        $member->method('getUserId')->willReturn('owner1');
        $member->method('canOperateDomain')->willReturn(true);
        $member->method('getDomainGroup')->willReturn('1');
        $member->method('getLevelName')->willReturn('운영자');
        $member->method('getLevelType')->willReturn('admin');

        $this->memberRepository->method('findByDomainAndUserId')->willReturn($member);
    }

    public function testCoreDoesNotLimitOwnedSiteCount(): void
    {
        $this->stubOwnerCandidate();

        // 코어는 소유 사이트 수를 세지 않는다 — 과거 "최대 1개" 정책의 회귀 방지
        $this->domainRepository->expects($this->never())->method('countByMemberId');

        $result = $this->makeService()->validateDomainOwner(1, 'owner1', '1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(5, $result->getData()['member_id']);
    }

    public function testPackagePolicyCanRejectViaValidatingEvent(): void
    {
        $this->stubOwnerCandidate();

        // 상용 패키지가 얹는 정책 시뮬레이션: 운영 수 제한
        $this->eventDispatcher->addListener(
            DomainOwnerValidatingEvent::class,
            function (DomainOwnerValidatingEvent $event): void {
                $this->assertSame(5, $event->getMemberId());
                $this->assertSame('owner1', $event->getUserId());
                $event->addError('현재 요금제에서는 더 이상 사이트를 만들 수 없습니다.');
            }
        );

        $result = $this->makeService()->validateDomainOwner(1, 'owner1', '1');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('요금제', $result->getMessage());
    }

    public function testPackagePolicyExceptionFailsClosed(): void
    {
        $this->stubOwnerCandidate();

        $this->eventDispatcher->addListener(
            DomainOwnerValidatingEvent::class,
            static function (): void {
                throw new \RuntimeException('policy database unavailable');
            }
        );

        $result = $this->makeService()->validateDomainOwner(1, 'owner1', '1');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('확인하지 못했습니다', $result->getMessage());
        $this->assertStringNotContainsString('database', $result->getMessage());
    }

    public function testCoreValidationStillRejectsMemberWithoutOperatePermission(): void
    {
        $member = $this->createMock(Member::class);
        $member->method('canOperateDomain')->willReturn(false);
        $member->method('getLevelName')->willReturn('일반회원');
        $this->memberRepository->method('findByDomainAndUserId')->willReturn($member);

        $result = $this->makeService()->validateDomainOwner(1, 'user2', '1');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('도메인 운영 권한', $result->getMessage());
    }
}
