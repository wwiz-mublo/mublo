<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Core\Crypto\PasswordHasher;
use Mublo\Service\Member\MemberService;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Repository\Member\MemberFieldRepository;
use Mublo\Service\Member\FieldEncryptionService;

#[CoversClass(MemberService::class)]
class MemberServiceTest extends TestCase
{
    private MemberService $service;
    private MockObject $repositoryMock;
    private MockObject $fieldRepositoryMock;
    private MockObject $encryptionServiceMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(MemberRepository::class);
        $this->fieldRepositoryMock = $this->createMock(MemberFieldRepository::class);
        // FieldEncryptionService는 security.php 파일이 필요하므로 Mock 처리
        $this->encryptionServiceMock = $this->createMock(FieldEncryptionService::class);
        $this->service = new MemberService(
            $this->repositoryMock,
            $this->fieldRepositoryMock,
            $this->encryptionServiceMock,
            new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 10]) // 낮은 cost = 테스트 속도
        );
    }

    #[Test]
    public function testRegisterWithDuplicateUserId(): void
    {
        // Arrange: domain_id를 data 배열 안에 포함
        $validData = [
            'domain_id' => 1,
            'user_id' => 'duplicateuser',
            'password' => 'password123',
        ];

        // checkUserIdAvailability → existsByUserId 호출됨
        $this->repositoryMock->expects($this->once())
            ->method('existsByUserId')
            ->with(1, 'duplicateuser')
            ->willReturn(true);

        // Act
        $result = $this->service->register($validData);

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('사용 중인 아이디', $result->getMessage());
    }

    #[Test]
    public function testVerifyPasswordMatchesCurrentPassword(): void
    {
        $hash = password_hash('secret123', PASSWORD_BCRYPT);
        $member = $this->createMock(\Mublo\Entity\Member\Member::class);
        $member->method('getPassword')->willReturn($hash);
        $this->repositoryMock->method('find')->willReturn($member);

        $this->assertTrue($this->service->verifyPassword(5, 'secret123'));
        $this->assertFalse($this->service->verifyPassword(5, 'wrong'));
        $this->assertFalse($this->service->verifyPassword(5, '')); // 빈값은 조회 전 거부
    }

    #[Test]
    public function testRegisterWithLongUserId(): void
    {
        // Arrange
        $invalidData = [
            'domain_id' => 1,
            'user_id' => 'thisuseridistoolongandshouldfail',
            'password' => 'password123',
        ];

        // The validation should fail BEFORE the DB check.
        $this->repositoryMock->expects($this->never())
            ->method('existsByUserId');

        // Act
        $result = $this->service->register($invalidData);

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('아이디는 영문, 숫자', $result->getMessage());
    }

    #[Test]
    public function testRegisterWithSpecialCharactersInUserId(): void
    {
        // Arrange
        $invalidData = [
            'domain_id' => 1,
            'user_id' => 'invalid-id!',
            'password' => 'password123',
        ];

        // The validation should fail BEFORE the DB check.
        $this->repositoryMock->expects($this->never())
            ->method('existsByUserId');

        // Act
        $result = $this->service->register($invalidData);

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('아이디는 영문, 숫자', $result->getMessage());
    }

    #[Test]
    public function testRegisterWithShortUserId(): void
    {
        // Arrange
        $invalidData = [
            'domain_id' => 1,
            'user_id' => 'sho',
            'password' => 'password123',
        ];

        // The validation should fail BEFORE the DB check.
        $this->repositoryMock->expects($this->never())
            ->method('existsByUserId');

        // Act
        $result = $this->service->register($invalidData);

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('아이디는 영문, 숫자', $result->getMessage());
    }

    #[Test]
    public function testRegisterRevalidatesCoreFieldsChangedByPreparingSubscriber(): void
    {
        $dispatcher = new \Mublo\Core\Event\EventDispatcher();
        $dispatcher->addListener(
            \Mublo\Core\Event\Member\MemberRegisterPreparingEvent::class,
            static function (\Mublo\Core\Event\Member\MemberRegisterPreparingEvent $event): void {
                $event->set('nickname', '<script>alert(1)</script>');
            }
        );
        $service = $this->makeServiceWithDispatcher($dispatcher);
        $this->repositoryMock->expects($this->exactly(2))
            ->method('existsByUserId')
            ->with(1, 'safeuser')
            ->willReturn(false);
        $this->repositoryMock->expects($this->never())->method('create');

        $result = $service->register([
            'domain_id' => 1,
            'user_id' => 'safeuser',
            'password' => 'password123',
        ], $this->createMock(\Mublo\Core\Context\Context::class));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('닉네임', $result->getMessage());
    }

    // ========================================
    // 탈퇴 이벤트 (회귀) — Withdrawing 차단 / Withdrawn 발행
    // ========================================

    /**
     * 이벤트 디스패처가 연결된 서비스 + 탈퇴 가능한 회원 목업 구성
     */
    private function makeServiceWithDispatcher(\Mublo\Core\Event\EventDispatcher $dispatcher): MemberService
    {
        return new MemberService(
            $this->repositoryMock,
            $this->fieldRepositoryMock,
            $this->encryptionServiceMock,
            new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 10]),
            null,
            null,
            $dispatcher
        );
    }

    private function withdrawableMember(): \Mublo\Entity\Member\Member
    {
        return \Mublo\Entity\Member\Member::fromArray([
            'member_id' => 100,
            'domain_id' => 1,
            'user_id' => 'hong',
            'password' => password_hash('pw1234', PASSWORD_BCRYPT, ['cost' => 10]),
            'level_value' => 1,
            'status' => 'active',
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    #[Test]
    public function testWithdrawBlockedBySubscriber(): void
    {
        $dispatcher = $this->createMock(\Mublo\Core\Event\EventDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) {
            if ($event instanceof \Mublo\Service\Member\Event\MemberWithdrawingEvent) {
                $event->setBlocked(true, '진행 중인 주문이 있어 탈퇴할 수 없습니다.');
            }
            return $event;
        });

        $service = $this->makeServiceWithDispatcher($dispatcher);
        $this->repositoryMock->method('find')->willReturn($this->withdrawableMember());
        // 차단 시 DB 반영이 일어나면 안 됨
        $this->repositoryMock->expects($this->never())->method('getDb');

        $result = $service->withdraw(100, 'pw1234', '개인 사유');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('진행 중인 주문', $result->getMessage());
    }

    #[Test]
    public function testWithdrawDispatchesWithdrawnEventAfterCommit(): void
    {
        $dispatched = [];
        $dispatcher = $this->createMock(\Mublo\Core\Event\EventDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = get_class($event);
            return $event;
        });

        $db = $this->createMock(\Mublo\Infrastructure\Database\Database::class);
        $db->method('transaction')->willReturnCallback(fn(callable $fn) => $fn());

        $service = $this->makeServiceWithDispatcher($dispatcher);
        $this->repositoryMock->method('find')->willReturn($this->withdrawableMember());
        $this->repositoryMock->method('getDb')->willReturn($db);
        $this->repositoryMock->expects($this->once())->method('deleteAllFieldValues')->with(100);
        $this->repositoryMock->expects($this->once())->method('softDelete');

        $result = $service->withdraw(100, 'pw1234', '개인 사유');

        $this->assertTrue($result->isSuccess());
        $this->assertContains(\Mublo\Service\Member\Event\MemberWithdrawingEvent::class, $dispatched);
        $this->assertContains(\Mublo\Service\Member\Event\MemberWithdrawnEvent::class, $dispatched);
    }

    // ========================================
    // 필드 쓰기 경계 (회귀) — 미지 field_id 거부·admin_only·타 도메인
    // ========================================

    #[Test]
    public function testValidateFieldValuesRejectsUnknownFieldId(): void
    {
        // 도메인 1에는 필드 정의가 없음 — 타 도메인 field_id(999) POST 시도
        $this->fieldRepositoryMock->method('findByDomain')->with(1)->willReturn([]);

        $result = $this->service->validateFieldValues(1, [999 => 'anything']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('허용되지 않은 필드', $result->getMessage());
    }

    #[Test]
    public function testValidateFieldValuesRejectsAdminOnlyFieldOnFrontPath(): void
    {
        $adminOnlyDef = [
            'field_id' => 20,
            'domain_id' => 1,
            'field_name' => 'internal_memo',
            'field_label' => '내부 메모',
            'field_type' => 'text',
            'is_required' => 0,
            'is_unique' => 0,
            'is_admin_only' => 1,
            'validation_rule' => null,
        ];
        $this->fieldRepositoryMock->method('findByDomain')->with(1)->willReturn([$adminOnlyDef]);

        // 프론트 경로 (allowAdminOnly=false 기본값) → 거부
        $front = $this->service->validateFieldValues(1, [20 => '조작 시도']);
        $this->assertFalse($front->isSuccess());
        $this->assertStringContainsString('허용되지 않은 필드', $front->getMessage());

        // 관리자 경로 (allowAdminOnly=true) → 허용
        $admin = $this->service->validateFieldValues(1, [20 => '관리자 메모'], true, null, true);
        $this->assertTrue($admin->isSuccess());
    }

    #[Test]
    public function testSaveFieldValuesThrowsOnCrossDomainField(): void
    {
        // 도메인 2 소속 필드를 도메인 1 회원 저장에 사용 시도
        $this->fieldRepositoryMock->method('findByIds')->willReturn([
            ['field_id' => 30, 'domain_id' => 2, 'field_name' => 'email', 'field_label' => '이메일',
             'field_type' => 'text', 'is_encrypted' => 0, 'is_searched' => 0, 'is_unique' => 0, 'is_admin_only' => 0],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/허용되지 않은 필드/');

        $this->service->saveFieldValues(100, [30 => 'x@y.com'], 1);
    }

    #[Test]
    public function testSaveFieldValuesThrowsOnUnknownFieldId(): void
    {
        $this->fieldRepositoryMock->method('findByIds')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/허용되지 않은 필드/');

        $this->service->saveFieldValues(100, [999 => 'x'], 1);
    }

    #[Test]
    public function testSaveFieldValuesWritesUniqueKeyForUniqueField(): void
    {
        // findByIds 가 is_unique 를 포함해 반환 → unique_key 가 실제로 기록되는지 (PR #221 결함 회귀)
        $this->fieldRepositoryMock->method('findByIds')->willReturn([
            ['field_id' => 10, 'domain_id' => 1, 'field_name' => 'email', 'field_label' => '이메일',
             'field_type' => 'email', 'is_encrypted' => 0, 'is_searched' => 0, 'is_unique' => 1, 'is_admin_only' => 0],
        ]);

        $this->encryptionServiceMock->method('processFieldValue')
            ->willReturnCallback(fn($v) => ['field_value' => $v, 'search_index' => null]);

        $expectedKey = hash('sha256', strtolower(trim('User@Example.com')));

        $this->repositoryMock->expects($this->once())
            ->method('saveFieldValue')
            ->with(100, 10, 'User@Example.com', null, $expectedKey);

        $this->service->saveFieldValues(100, [10 => 'User@Example.com'], 1);
    }

    // ========================================
    // validateFieldValues — is_unique 서버측 강제 (회귀)
    // ========================================

    /**
     * 유일 필드 정의 샘플
     */
    private function uniqueEmailFieldDef(): array
    {
        return [
            'field_id' => 10,
            'domain_id' => 1,
            'field_name' => 'email',
            'field_label' => '이메일',
            'field_type' => 'email',
            'is_required' => 0,
            'is_unique' => 1,
            'is_encrypted' => 0,
            'validation_rule' => null,
        ];
    }

    #[Test]
    public function testValidateFieldValuesRejectsDuplicateUniqueValue(): void
    {
        $fieldDef = $this->uniqueEmailFieldDef();

        $this->fieldRepositoryMock->method('findByDomain')->with(1)->willReturn([$fieldDef]);
        $this->fieldRepositoryMock->method('findByDomainAndName')->with(1, 'email')->willReturn($fieldDef);

        // 다른 회원이 이미 같은 값 보유
        $this->repositoryMock->expects($this->once())
            ->method('existsFieldValue')
            ->with(1, 10, 'dup@example.com', null)
            ->willReturn(true);

        $result = $this->service->validateFieldValues(1, [10 => 'dup@example.com']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('이미 사용 중인 이메일', $result->getMessage());
    }

    #[Test]
    public function testValidateFieldValuesExcludesSelfOnUpdate(): void
    {
        $fieldDef = $this->uniqueEmailFieldDef();

        $this->fieldRepositoryMock->method('findByDomain')->with(1)->willReturn([$fieldDef]);
        $this->fieldRepositoryMock->method('findByDomainAndName')->with(1, 'email')->willReturn($fieldDef);

        // 본인(member_id=55) 값은 중복 판정에서 제외되어야 함
        $this->repositoryMock->expects($this->once())
            ->method('existsFieldValue')
            ->with(1, 10, 'mine@example.com', 55)
            ->willReturn(false);

        $result = $this->service->validateFieldValues(1, [10 => 'mine@example.com'], false, 55);

        $this->assertTrue($result->isSuccess());
    }

    // ========================================
    // backfillFieldUniqueKeys — is_unique 토글 백필 (회귀)
    // ========================================

    #[Test]
    public function testBackfillFieldUniqueKeysDetectsDuplicates(): void
    {
        // 평문 필드 — readFieldValue 는 원문 그대로 반환
        $this->encryptionServiceMock->method('readFieldValue')
            ->willReturnCallback(fn($stored, $enc) => $stored);

        // 대소문자·공백만 다른 두 값 = 정규화 후 동일 → 중복
        $this->repositoryMock->method('getFieldValueRowsByField')->with(10)->willReturn([
            ['value_id' => 1, 'member_id' => 100, 'field_value' => 'Dup@Example.com', 'search_index' => null, 'unique_key' => null],
            ['value_id' => 2, 'member_id' => 200, 'field_value' => ' dup@example.com ', 'search_index' => null, 'unique_key' => null],
        ]);

        $this->repositoryMock->expects($this->never())->method('updateFieldValueKeys');

        $result = $this->service->backfillFieldUniqueKeys(10, $this->uniqueEmailFieldDef());

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('중복', $result->getMessage());
    }

    #[Test]
    public function testBackfillFieldUniqueKeysBackfillsDistinctValues(): void
    {
        $this->encryptionServiceMock->method('readFieldValue')
            ->willReturnCallback(fn($stored, $enc) => $stored);

        $this->repositoryMock->method('getFieldValueRowsByField')->with(10)->willReturn([
            ['value_id' => 1, 'member_id' => 100, 'field_value' => 'a@example.com', 'search_index' => null, 'unique_key' => null],
            ['value_id' => 2, 'member_id' => 200, 'field_value' => 'b@example.com', 'search_index' => null, 'unique_key' => null],
        ]);

        $this->repositoryMock->expects($this->exactly(2))->method('updateFieldValueKeys');

        $result = $this->service->backfillFieldUniqueKeys(10, $this->uniqueEmailFieldDef());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->get('backfilled'));
    }
}
