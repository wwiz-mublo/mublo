<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Service\Member\MemberAdminService;
use Mublo\Service\Member\MemberService;
use Mublo\Service\Member\FieldEncryptionService;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Repository\Member\MemberFieldRepository;
use Mublo\Entity\Member\Member;

/**
 * 관리자 계정 보호 회귀 테스트.
 *
 * 정책: 관리자 레벨은 상하 서열이 아니다. 따라서 슈퍼가 아닌 관리자는
 * 동일 도메인의 다른 관리자 계정을 수정/삭제할 수 없다(본인 제외).
 * 이 가드가 없으면 A 관리자가 B 관리자의 비밀번호·상태를 바꿔 계정을 탈취/잠글 수 있다.
 */
#[CoversClass(MemberAdminService::class)]
class MemberAdminServiceTest extends TestCase
{
    private MemberAdminService $service;
    private MockObject $repositoryMock;
    private MockObject $memberServiceMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(MemberRepository::class);
        $this->memberServiceMock = $this->createMock(MemberService::class);
        $this->service = new MemberAdminService(
            $this->repositoryMock,
            $this->createMock(MemberFieldRepository::class),
            $this->createMock(FieldEncryptionService::class),
            $this->memberServiceMock
        );
    }

    /**
     * @param bool $isAdmin 대상이 관리자인지
     * @param bool $isSuper 대상이 슈퍼인지
     */
    private function makeTargetMember(int $memberId, int $domainId, bool $isAdmin, bool $isSuper = false): MockObject
    {
        $member = $this->createMock(Member::class);
        $member->method('getMemberId')->willReturn($memberId);
        $member->method('getDomainId')->willReturn($domainId);
        $member->method('getOriginDomainId')->willReturn(null);
        $member->method('isSuper')->willReturn($isSuper);
        // 엔티티 규약: isAdmin()은 슈퍼를 포함한다.
        $member->method('isAdmin')->willReturn($isAdmin || $isSuper);
        return $member;
    }

    // ----- update() -----

    #[Test]
    public function testNonSuperAdminCannotUpdateAnotherAdmin(): void
    {
        // 대상: 같은 도메인의 다른 관리자
        $target = $this->makeTargetMember(memberId: 20, domainId: 1, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);

        // 요청자: 비-슈퍼 관리자(memberId 10), 대상의 비밀번호 변경 시도
        $result = $this->service->update(20, [
            'admin_id' => 10,
            'admin_domain_id' => 1,
            'admin_is_super' => false,
            'password' => 'newpassword123',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('다른 관리자', $result->getMessage());
    }

    #[Test]
    public function testUpdateFailsClosedWhenAdminIdMissing(): void
    {
        // admin_id 부재 → 본인 식별 불가 → 타인으로 취급(fail-closed)
        $target = $this->makeTargetMember(memberId: 20, domainId: 1, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);

        $result = $this->service->update(20, [
            'admin_domain_id' => 1,
            'admin_is_super' => false,
            'status' => 'blocked',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('다른 관리자', $result->getMessage());
    }

    #[Test]
    public function testUpdateFailsClosedWhenSuperFlagMissing(): void
    {
        // admin_is_super 부재 → 비-슈퍼로 취급(fail-closed)
        $target = $this->makeTargetMember(memberId: 20, domainId: 1, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);

        $result = $this->service->update(20, [
            'admin_id' => 10,
            'admin_domain_id' => 1,
            'password' => 'newpassword123',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('다른 관리자', $result->getMessage());
    }

    #[Test]
    public function testNonSuperAdminCanUpdateOwnAccount(): void
    {
        // 본인(memberId 10) 수정은 이 가드에 걸리지 않아야 한다.
        // 변경 필드 없이 통과시켜 "다른 관리자" 거부가 나오지 않음을 확인한다.
        $target = $this->makeTargetMember(memberId: 10, domainId: 1, isAdmin: true);
        $this->repositoryMock->method('find')->with(10)->willReturn($target);
        $this->stubTransaction();

        $result = $this->service->update(10, [
            'admin_id' => 10,
            'admin_domain_id' => 1,
            'admin_is_super' => false,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testNonSuperAdminCanUpdateRegularMember(): void
    {
        // 대상이 일반 회원이면 가드를 건너뛴다.
        $target = $this->makeTargetMember(memberId: 30, domainId: 1, isAdmin: false);
        $this->repositoryMock->method('find')->with(30)->willReturn($target);
        $this->stubTransaction();

        $result = $this->service->update(30, [
            'admin_id' => 10,
            'admin_domain_id' => 1,
            'admin_is_super' => false,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testSuperAdminCanUpdateAnotherAdmin(): void
    {
        // 슈퍼는 다른 관리자를 수정할 수 있다.
        $target = $this->makeTargetMember(memberId: 20, domainId: 1, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);
        $this->stubTransaction();

        $result = $this->service->update(20, [
            'admin_id' => 1,
            'admin_domain_id' => 1,
            'admin_is_super' => true,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    // ----- delete() -----

    #[Test]
    public function testNonSuperAdminCannotDeleteAnotherAdmin(): void
    {
        $target = $this->makeTargetMember(memberId: 20, domainId: 1, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);

        $result = $this->service->delete(20, [
            'admin_domain_id' => 1,
            'admin_is_super' => false,
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('다른 관리자', $result->getMessage());
    }

    #[Test]
    public function testSuperAdminCanDeleteAnotherAdmin(): void
    {
        $target = $this->makeTargetMember(memberId: 20, domainId: 1, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);
        $this->repositoryMock->method('delete')->with(20)->willReturn(1);

        $result = $this->service->delete(20, [
            'admin_domain_id' => 1,
            'admin_is_super' => true,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    // ----- 도메인 주인 예외: 소유권(domain_configs.member_id)만으로 판정 -----

    #[Test]
    public function testDomainOwnerCanUpdateAdminInOwnDomain(): void
    {
        // 대상: 도메인 5의 다른 관리자
        $target = $this->makeTargetMember(memberId: 20, domainId: 5, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);
        // 요청자(10)는 도메인 5의 실제 소유자(domain_configs.member_id)
        $this->memberServiceMock->method('ownsDomainId')->with(10, 5)->willReturn(true);
        $this->stubTransaction();

        $result = $this->service->update(20, [
            'admin_id' => 10,
            'admin_domain_id' => 5,
            'admin_is_super' => false,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testDomainOwnerCannotUpdateSuperAdmin(): void
    {
        // 도메인 소유자(비-슈퍼)라도 슈퍼 계정은 수정할 수 없다(delete 의 슈퍼 차단과 대칭).
        $target = $this->makeTargetMember(memberId: 20, domainId: 5, isAdmin: true, isSuper: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);
        $this->memberServiceMock->method('ownsDomainId')->willReturn(true); // 소유자여도

        $result = $this->service->update(20, [
            'admin_id' => 10,
            'admin_domain_id' => 5,
            'admin_is_super' => false,
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('최고관리자', $result->getMessage());
    }

    #[Test]
    public function testNonOwnerAdminCannotUpdateAnotherAdmin(): void
    {
        // 이 도메인의 소유자가 아님 → 거부(등급과 무관)
        $target = $this->makeTargetMember(memberId: 20, domainId: 5, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);
        $this->memberServiceMock->method('ownsDomainId')->with(10, 5)->willReturn(false);

        $result = $this->service->update(20, [
            'admin_id' => 10,
            'admin_domain_id' => 5,
            'admin_is_super' => false,
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('다른 관리자', $result->getMessage());
    }

    #[Test]
    public function testDomainOwnerCanDeleteAdminInOwnDomain(): void
    {
        $target = $this->makeTargetMember(memberId: 20, domainId: 5, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);
        $this->repositoryMock->method('delete')->with(20)->willReturn(1);
        $this->memberServiceMock->method('ownsDomainId')->with(10, 5)->willReturn(true);

        $result = $this->service->delete(20, [
            'admin_domain_id' => 5,
            'admin_is_super' => false,
            'admin_id' => 10,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testNonOwnerAdminCannotDeleteAnotherAdmin(): void
    {
        $target = $this->makeTargetMember(memberId: 20, domainId: 5, isAdmin: true);
        $this->repositoryMock->method('find')->with(20)->willReturn($target);
        $this->memberServiceMock->method('ownsDomainId')->with(10, 5)->willReturn(false);

        $result = $this->service->delete(20, [
            'admin_domain_id' => 5,
            'admin_is_super' => false,
            'admin_id' => 10,
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('다른 관리자', $result->getMessage());
    }

    /**
     * update()의 트랜잭션 경로를 통과시키기 위한 최소 스텁.
     * 변경 필드가 없으면 beginTransaction → commit 만 실행된다.
     */
    private function stubTransaction(): void
    {
        $db = $this->createMock(\Mublo\Infrastructure\Database\Database::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $this->repositoryMock->method('getDb')->willReturn($db);
    }
}
