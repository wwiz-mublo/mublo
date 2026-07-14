<?php

namespace Tests\Unit\Service\Member;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Core\Crypto\PasswordHasher;
use Mublo\Service\Member\MemberService;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Repository\Member\MemberFieldRepository;
use Mublo\Service\Member\FieldEncryptionService;

/**
 * MemberValidationTest
 *
 * MemberService 검증 메서드 단위 테스트
 * - validateUserId (일반 모드 / 이메일 모드)
 * - validateNickname
 * - checkUserIdAvailability
 * - isUserIdAvailable
 */
#[CoversClass(MemberService::class)]
class MemberValidationTest extends TestCase
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
        $this->encryptionServiceMock = $this->createMock(FieldEncryptionService::class);

        $this->service = new MemberService(
            $this->repositoryMock,
            $this->fieldRepositoryMock,
            $this->encryptionServiceMock,
            new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 10]) // 낮은 cost = 테스트 속도
        );
    }

    // =========================================================================
    // validateUserId - 일반 모드
    // =========================================================================

    #[Test]
    public function testValidateUserIdWithValidId(): void
    {
        $result = $this->service->validateUserId('testuser');
        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testValidateUserIdWithMinLength(): void
    {
        // 4자 (최소)
        $result = $this->service->validateUserId('abcd');
        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testValidateUserIdWithMaxLength(): void
    {
        // 20자 (최대)
        $result = $this->service->validateUserId('abcdefghij1234567890');
        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testValidateUserIdWithUnderscoreAllowed(): void
    {
        // 밑줄 허용
        $result = $this->service->validateUserId('test_user_123');
        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testValidateUserIdRejectsEmpty(): void
    {
        $result = $this->service->validateUserId('');
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('아이디', $result->getMessage());
    }

    #[Test]
    public function testValidateUserIdRejectsTooShort(): void
    {
        // 3자 (최소 4자)
        $result = $this->service->validateUserId('abc');
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('4~20자', $result->getMessage());
    }

    #[Test]
    public function testValidateUserIdRejectsTooLong(): void
    {
        // 21자 (최대 20자)
        $result = $this->service->validateUserId('abcdefghij12345678901');
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('4~20자', $result->getMessage());
    }

    #[Test]
    public function testValidateUserIdRejectsSpecialCharacters(): void
    {
        $result = $this->service->validateUserId('invalid-id!');
        $this->assertFalse($result->isSuccess());
    }

    #[Test]
    public function testValidateUserIdRejectsKorean(): void
    {
        $result = $this->service->validateUserId('한글아이디');
        $this->assertFalse($result->isSuccess());
    }

    #[Test]
    public function testValidateUserIdRejectsDash(): void
    {
        // 하이픈 불허 (밑줄만 허용)
        $result = $this->service->validateUserId('test-user');
        $this->assertFalse($result->isSuccess());
    }

    // =========================================================================
    // validateUserId - 이메일 모드
    // =========================================================================

    #[Test]
    public function testValidateUserIdEmailModeWithValidEmail(): void
    {
        $result = $this->service->validateUserId('user@example.com', true);
        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testValidateUserIdEmailModeRejectsInvalidEmail(): void
    {
        $result = $this->service->validateUserId('not-an-email', true);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('이메일', $result->getMessage());
    }

    #[Test]
    public function testValidateUserIdEmailModeRejectsTooLongEmail(): void
    {
        // 51자 이메일 (최대 50자)
        $longEmail = str_repeat('a', 40) . '@example.com'; // 52자
        $result = $this->service->validateUserId($longEmail, true);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('50자', $result->getMessage());
    }

    #[Test]
    public function testValidateUserIdEmailModeRejectsEmpty(): void
    {
        $result = $this->service->validateUserId('', true);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('이메일', $result->getMessage());
    }

    // =========================================================================
    // validateNickname
    // =========================================================================

    #[Test]
    public function testValidateNicknameWithValidNickname(): void
    {
        $result = $this->service->validateNickname('테스트닉네임');
        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function testValidateNicknameRejectsEmpty(): void
    {
        $result = $this->service->validateNickname('');
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('닉네임', $result->getMessage());
    }

    // =========================================================================
    // isUserIdAvailable / checkUserIdAvailability
    // =========================================================================

    #[Test]
    public function testIsUserIdAvailableReturnsTrueForUniqueId(): void
    {
        // Given: 중복 없음
        $this->repositoryMock->expects($this->once())
            ->method('existsByUserId')
            ->with(1, 'newuser')
            ->willReturn(false);

        // When / Then
        $this->assertTrue($this->service->isUserIdAvailable(1, 'newuser'));
    }

    #[Test]
    public function testIsUserIdAvailableReturnsFalseForDuplicateId(): void
    {
        // Given: 중복 있음
        $this->repositoryMock->expects($this->once())
            ->method('existsByUserId')
            ->with(1, 'existinguser')
            ->willReturn(true);

        // When / Then
        $this->assertFalse($this->service->isUserIdAvailable(1, 'existinguser'));
    }

    #[Test]
    public function testCheckUserIdAvailabilityWithValidUniqueId(): void
    {
        // Given
        $this->repositoryMock->expects($this->once())
            ->method('existsByUserId')
            ->with(1, 'newuser123')
            ->willReturn(false);

        // When
        $result = $this->service->checkUserIdAvailability(1, 'newuser123');

        // Then
        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('사용 가능', $result->getMessage());
    }

    #[Test]
    public function testCheckUserIdAvailabilityFailsForDuplicateId(): void
    {
        // Given
        $this->repositoryMock->expects($this->once())
            ->method('existsByUserId')
            ->with(1, 'dupuser')
            ->willReturn(true);

        // When
        $result = $this->service->checkUserIdAvailability(1, 'dupuser');

        // Then
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('사용 중인', $result->getMessage());
    }

    #[Test]
    public function testCheckUserIdAvailabilityFailsBeforeDbCheckForInvalidFormat(): void
    {
        // 형식 오류는 DB 조회 전에 실패해야 함
        $this->repositoryMock->expects($this->never())
            ->method('existsByUserId');

        $result = $this->service->checkUserIdAvailability(1, 'ab'); // 너무 짧음
        $this->assertFalse($result->isSuccess());
    }

    #[Test]
    public function testCheckUserIdAvailabilityEmailModeWithDuplicate(): void
    {
        // Given: 이메일 모드에서 중복
        $this->repositoryMock->expects($this->once())
            ->method('existsByUserId')
            ->with(1, 'dup@example.com')
            ->willReturn(true);

        $result = $this->service->checkUserIdAvailability(1, 'dup@example.com', true);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('이메일', $result->getMessage());
    }
}
