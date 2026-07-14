<?php

namespace Tests\Board\Unit\Service{

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardCategoryMapping;
use Mublo\Core\Context\Context;
use Mublo\Entity\Member\Member;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardPermissionRepository;
use Mublo\Service\Auth\AuthService;
use Mublo\Contract\Auth\AuthenticatedUser;

/**
 * AuthContext의 안정 접근자와 AuthenticatedUser DTO를 기준으로 권한을 검증합니다.
 */
#[CoversClass(BoardPermissionService::class)]
class BoardPermissionServiceTest extends TestCase
{
    private BoardPermissionService $permissionService;
    private $groupRepositoryMock;
    private $mappingRepositoryMock;
    private $permissionRepositoryMock;
    private $authServiceMock;

    protected function setUp(): void
    {
        // Repository 의존성을 Mock 객체로 대체
        $this->groupRepositoryMock = $this->createMock(BoardGroupRepository::class);
        $this->mappingRepositoryMock = $this->createMock(BoardCategoryMappingRepository::class);
        $this->permissionRepositoryMock = $this->createMock(BoardPermissionRepository::class);
        $this->authServiceMock = $this->createMock(AuthService::class);

        $this->permissionService = new BoardPermissionService(
            $this->groupRepositoryMock,
            $this->mappingRepositoryMock,
            $this->permissionRepositoryMock,
            $this->authServiceMock
        );
    }

    #[Test]
    public function testCanListAsAdmin(): void
    {
        // Arrange: 관리자 유저와 게시판 설정 준비
        $boardConfig = $this->createBoardConfig();
        $adminMember = $this->createMember(['level_value' => 100, 'is_admin' => 1]);
        $this->setupAuthUser($adminMember);
        $context = $this->createContext($adminMember, true);

        // Act: 권한 체크 실행
        $result = $this->permissionService->canList($boardConfig, $context);

        // Assert: 결과는 true여야 함
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanListWithSufficientLevel(): void
    {
        // Arrange
        $boardConfig = $this->createBoardConfig(['list_level' => 5]);
        $member = $this->createMember(['level_value' => 5]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);

        // Act
        $result = $this->permissionService->canList($boardConfig, $context);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testCannotListWithInsufficientLevel(): void
    {
        // Arrange
        $boardConfig = $this->createBoardConfig(['list_level' => 5]);
        $member = $this->createMember(['level_value' => 4]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);

        // Act
        $result = $this->permissionService->canList($boardConfig, $context);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function testCanReadSecretPostAsAuthor(): void
    {
        // Arrange
        $boardConfig = $this->createBoardConfig();
        $authorId = 123;
        $article = $this->createBoardArticle(['is_secret' => 1, 'member_id' => $authorId]);
        $member = $this->createMember(['member_id' => $authorId]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);

        // Act
        $result = $this->permissionService->canRead($boardConfig, $article, $context);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testCannotReadSecretPostAsOtherUser(): void
    {
        // Arrange
        $boardConfig = $this->createBoardConfig();
        $article = $this->createBoardArticle(['is_secret' => 1, 'member_id' => 123]);
        $otherMember = $this->createMember(['member_id' => 456]);
        $this->setupAuthUser($otherMember);
        $context = $this->createContext($otherMember);

        // Act
        $result = $this->permissionService->canRead($boardConfig, $article, $context);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function testCanWriteWithSufficientLevel(): void
    {
        $boardConfig = $this->createBoardConfig(['allow_guest' => 0, 'write_level' => 2]);
        $member = $this->createMember(['level_value' => 2]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);

        $result = $this->permissionService->canWrite($boardConfig, $context);

        $this->assertTrue($result);
    }

    #[Test]
    public function testCannotWriteWithCategoryNotMappedToBoard(): void
    {
        $boardConfig = $this->createBoardConfig(['use_category' => 1, 'write_level' => 0]);
        $member = $this->createMember(['level_value' => 10]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);
        $this->mappingRepositoryMock->expects($this->once())
            ->method('findByBoardAndCategory')
            ->with(1, 999)
            ->willReturn(null);

        $result = $this->permissionService->canWrite($boardConfig, $context, 999);

        $this->assertFalse($result);
    }

    #[Test]
    public function testCannotWriteWithInactiveCategoryMapping(): void
    {
        $boardConfig = $this->createBoardConfig(['use_category' => 1, 'write_level' => 0]);
        $member = $this->createMember(['level_value' => 10]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);
        $this->mappingRepositoryMock->method('findByBoardAndCategory')->willReturn(
            BoardCategoryMapping::fromArray([
                'mapping_id' => 1,
                'board_id' => 1,
                'category_id' => 3,
                'is_active' => 0,
            ])
        );

        $result = $this->permissionService->canWrite($boardConfig, $context, 3);

        $this->assertFalse($result);
    }

    #[Test]
    public function testCanModifyAsAdmin(): void
    {
        $boardConfig = $this->createBoardConfig();
        $article = $this->createBoardArticle(['member_id' => 123]);
        $adminMember = $this->createMember(['level_value' => 100, 'is_admin' => 1]);
        $this->setupAuthUser($adminMember);
        $context = $this->createContext($adminMember, true);

        $result = $this->permissionService->canModify($boardConfig, $article, $context);

        $this->assertTrue($result);
    }

    #[Test]
    public function testCanModifyAsAuthor(): void
    {
        $boardConfig = $this->createBoardConfig();
        $authorId = 123;
        $article = $this->createBoardArticle(['member_id' => $authorId]);
        $member = $this->createMember(['member_id' => $authorId]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);

        $result = $this->permissionService->canModify($boardConfig, $article, $context);

        $this->assertTrue($result);
    }

    #[Test]
    public function testCannotModifyAsOtherUser(): void
    {
        $boardConfig = $this->createBoardConfig();
        $article = $this->createBoardArticle(['member_id' => 123]);
        $otherMember = $this->createMember(['member_id' => 456]);
        $this->setupAuthUser($otherMember);
        $context = $this->createContext($otherMember);

        $result = $this->permissionService->canModify($boardConfig, $article, $context);

        $this->assertFalse($result);
    }

    #[Test]
    public function testCanDeleteAsAuthor(): void
    {
        $boardConfig = $this->createBoardConfig();
        $authorId = 123;
        $article = $this->createBoardArticle(['member_id' => $authorId]);
        $member = $this->createMember(['member_id' => $authorId]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);

        $result = $this->permissionService->canDelete($boardConfig, $article, $context);

        $this->assertTrue($result);
    }

    #[Test]
    public function testCannotDownloadWhenFileUsageDisabled(): void
    {
        $boardConfig = $this->createBoardConfig(['use_file' => 0]);
        $member = $this->createMember();
        $this->setupAuthUser($member);
        $context = $this->createContext($member);

        $result = $this->permissionService->canDownload($boardConfig, null, $context);

        $this->assertFalse($result);
    }

    #[Test]
    public function testCanDownloadWithSufficientLevel(): void
    {
        $boardConfig = $this->createBoardConfig(['use_file' => 1, 'download_level' => 1]);
        $member = $this->createMember(['level_value' => 1]);
        $this->setupAuthUser($member);
        $context = $this->createContext($member);
        $result = $this->permissionService->canDownload($boardConfig, null, $context);

        $this->assertTrue($result);
    }

    #[Test]
    public function testCanDownloadAsGuest(): void
    {
        $boardConfig = $this->createBoardConfig(['use_file' => 1, 'guest_download_level' => 0]);
        // 비회원 Context (Member is null)
        $this->setupAuthUser(null);
        $context = $this->createContext(null);

        $result = $this->permissionService->canDownload($boardConfig, null, $context);

        $this->assertTrue($result);
    }

    // --- Helper Methods for creating mocks ---

    private function createBoardConfig(array $overrides = []): BoardConfig
    {
        $defaults = [
            'board_id' => 1,
            'domain_id' => 1,
            'group_id' => 1,
            'list_level' => 0,
            'read_level' => 0,
            'write_level' => 1,
            'comment_level' => 1,
            'download_level' => 0,
            'allow_guest' => 0,
            'guest_download_level' => 0,
            'use_file' => 1,
            'board_admin_ids' => '[]',
        ];
        // BoardConfig Entity가 JSON을 배열로 변환한다고 가정
        $data = array_merge($defaults, $overrides);
        if (is_array($data['board_admin_ids'])) {
            $data['board_admin_ids'] = json_encode($data['board_admin_ids']);
        }
        return BoardConfig::fromArray($data);
    }

    private function createBoardArticle(array $overrides = []): BoardArticle
    {
        $defaults = [
            'article_id' => 1,
            'domain_id' => 1,
            'board_id' => 1,
            'member_id' => null,
            'is_secret' => 0,
        ];
        return BoardArticle::fromArray(array_merge($defaults, $overrides));
    }

    private function createMember(array $overrides = []): Member
    {
        $defaults = [
            'member_id' => 1,
            'level_value' => 1,
            'is_super' => 0, // member_levels 테이블의 is_super
            'is_admin' => 0, // member_levels 테이블의 is_admin
        ];
        return Member::fromArray(array_merge($defaults, $overrides));
    }

    private function createContext(?Member $member, bool $isAdmin = false): Context
    {
        // Context 모의 객체 사용 (실제 Context는 Request를 필요로 함)
        $context = $this->createMock(Context::class);
        $context->method('isAdmin')->willReturn($isAdmin);
        return $context;
    }

    /**
     * AuthContext 반환값 설정 헬퍼
     */
    private function setupAuthUser(?Member $member): void
    {
        if ($member === null) {
            $this->authServiceMock->method('currentUser')->willReturn(null);
            $this->authServiceMock->method('id')->willReturn(null);
            $this->authServiceMock->method('guest')->willReturn(true);
        } else {
            $user = new AuthenticatedUser(
                memberId: $member->getMemberId(),
                domainId: $member->getDomainId(),
                userId: $member->getUserId(),
                nickname: $member->getNickname(),
                levelValue: $member->getLevelValue(),
                admin: $member->isAdmin(),
                super: $member->isSuper(),
                canOperateDomain: false,
                avatar: null,
            );
            $this->authServiceMock->method('currentUser')->willReturn($user);
            $this->authServiceMock->method('id')->willReturn($member->getMemberId());
            $this->authServiceMock->method('guest')->willReturn(false);
            $this->authServiceMock->method('isAdmin')->willReturn($member->isAdmin() || $member->isSuper());
            $this->authServiceMock->method('isSuper')->willReturn($member->isSuper());
        }
    }
}
}

// PHPUnit이 클래스를 찾을 수 있도록 테스트 파일 내에 스텁(Stub) 클래스를 정의합니다.
// 실제 클래스와 충돌하지 않도록 `class_exists`로 감쌉니다.
namespace Mublo\Entity\Board {
    if (!class_exists(BoardConfig::class)) {
        class BoardConfig {
            private array $data;
            public static function fromArray(array $data): self { $instance = new self; $instance->data = $data; return $instance; }
            public function __call(string $name, array $arguments) {
                $key = ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $name)), '_');
                if (str_starts_with($name, 'get')) { $key = substr($key, 4); }
                if (str_starts_with($name, 'is')) { $key = substr($key, 3); }
                if ($key === 'board_admin_ids' && is_string($this->data[$key])) { return json_decode($this->data[$key], true) ?? []; }
                return $this->data[$key] ?? null;
            }
        }
    }
    if (!class_exists(BoardArticle::class)) {
        final class BoardArticle { // final 키워드 추가
            private array $data;
            public static function fromArray(array $data): self { $instance = new self; $instance->data = $data; return $instance; }
            public function isSecret(): bool { return !empty($this->data['is_secret']); }
            public function getMemberId(): ?int { return $this->data['member_id'] ?? null; }
            public function isMemberArticle(): bool { return !empty($this->data['member_id']); }
            public function isAuthor(int $memberId): bool { return ($this->data['member_id'] ?? null) === $memberId; }
            public function __call(string $name, array $arguments) {
                $key = ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $name)), '_');
                if (str_starts_with($name, 'get')) { $key = substr($key, 4); }
                return $this->data[$key] ?? null;
            }
        }
    }
}

namespace Mublo\Entity\Member {
    if (!class_exists(Member::class)) {
        class Member {
            private array $data;
            public static function fromArray(array $data): self { $instance = new self; $instance->data = $data; return $instance; }
            public function getMemberId(): int { return $this->data['member_id']; }
            public function getLevelValue(): int { return $this->data['level_value']; }
            public function isSuper(): bool { return !empty($this->data['is_super']); }
            public function isAdmin(): bool { return !empty($this->data['is_admin']); }
        }
    }
}

namespace Mublo\Core\Context {
    if (!class_exists(Context::class)) {
        class Context {
            private ?\Mublo\Entity\Member\Member $member;
            private bool $isAdmin;
            public function __construct(?\Mublo\Entity\Member\Member $member, bool $isAdmin = false) {
                $this->member = $member;
                $this->isAdmin = $isAdmin;
            }
            public function getMember(): ?\Mublo\Entity\Member\Member { return $this->member; }
            public function isAdmin(): bool { return $this->isAdmin; }
        }
    }
}
