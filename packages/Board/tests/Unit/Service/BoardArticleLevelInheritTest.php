<?php

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Auth\AuthenticatedUser;
use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardPermissionRepository;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Service\Auth\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * 글 개별 레벨은 게시판 설정을 "덮는" 예외다 — 명시적으로 지정될 때만 걸려야 한다.
 *
 * 배경: board_articles 의 레벨 컬럼은 NULL 이 '게시판 설정 따름'을 뜻한다. 그런데
 * 게시판을 회원 전용으로 두었는데도 비회원이 첨부를 받아 가는 일이 있었다. 글에
 * download_level = 0 이 박혀 게시판 정책을 덮고 있었고, 어느 화면에도 그 사실이
 * 드러나지 않아 원인을 찾는 데 오래 걸렸다.
 *
 * 여기서 고정하는 계약:
 *   - 게시판 하한보다 낮추는 것은 관리자만 (조이는 방향은 누구나)
 *   - 판정 결과의 출처를 알 수 있어야 한다 (관리자 화면이 어긋남을 표시하려면 필요)
 */
class BoardArticleLevelInheritTest extends TestCase
{
    private BoardPermissionService $permissionService;
    private $authServiceMock;

    protected function setUp(): void
    {
        $this->authServiceMock = $this->createMock(AuthService::class);

        $this->permissionService = new BoardPermissionService(
            $this->createMock(BoardGroupRepository::class),
            $this->createMock(BoardCategoryMappingRepository::class),
            $this->createMock(BoardPermissionRepository::class),
            $this->authServiceMock,
        );
    }

    public function testMemberCannotLowerLevelBelowBoardPolicy(): void
    {
        $this->loginAs(levelValue: 1, admin: false);

        $resolved = $this->permissionService->resolveArticleLevel(
            $this->board(['download_level' => 1]),
            $this->context(),
            'download',
            0, // 전체 공개로 풀려는 시도
        );

        // 거절이 아니라 상속으로 되돌린다 — 글쓰기 자체를 실패시킬 사안이 아니다
        $this->assertNull($resolved, '게시판 하한보다 낮추면 상속(null)으로 되돌아가야 한다');
    }

    public function testMemberCanRaiseLevelAboveBoardPolicy(): void
    {
        $this->loginAs(levelValue: 1, admin: false);

        $resolved = $this->permissionService->resolveArticleLevel(
            $this->board(['download_level' => 1]),
            $this->context(),
            'download',
            5, // 조이는 방향
        );

        $this->assertSame(5, $resolved);
    }

    public function testAdminCanLowerLevelBelowBoardPolicy(): void
    {
        $this->loginAs(levelValue: 100, admin: true);

        $resolved = $this->permissionService->resolveArticleLevel(
            $this->board(['download_level' => 1]),
            $this->context(isAdmin: true),
            'download',
            0,
        );

        $this->assertSame(0, $resolved, '관리자는 개별 예외를 걸 수 있어야 한다');
    }

    public function testNullStaysNull(): void
    {
        $this->loginAs(levelValue: 1, admin: false);

        $this->assertNull($this->permissionService->resolveArticleLevel(
            $this->board(['download_level' => 1]),
            $this->context(),
            'download',
            null,
        ));
    }

    /**
     * 관리자 화면이 "게시판 설정"과 "이 글 개별 설정"을 구분해 보여주려면 출처가 필요하다.
     */
    public function testDescribeReportsBoardAsSourceWhenArticleHasNoLevel(): void
    {
        $described = $this->permissionService->describeRequiredLevel(
            $this->board(['download_level' => 2]),
            null,
            'download',
        );

        $this->assertSame(['value' => 2, 'source' => 'board'], $described);
    }

    public function testDescribeReportsArticleAsSourceWhenArticleOverrides(): void
    {
        $article = BoardArticle::fromArray([
            'article_id' => 10,
            'board_id' => 1,
            'domain_id' => 1,
            'title' => 't',
            'download_level' => 0,
        ]);

        $described = $this->permissionService->describeRequiredLevel(
            $this->board(['download_level' => 2]),
            $article,
            'download',
        );

        $this->assertSame(['value' => 0, 'source' => 'article'], $described);
    }

    /**
     * 입력 정규화 — 판단할 수 없는 값이 0(비회원 허용)으로 떨어지면 안 된다.
     *
     * 예전 구현은 isset() + (int) 캐스팅이라 빈 문자열도, 숫자가 아닌 값도 전부 0 이
     * 됐다. 권한 코드가 fail-open 인 셈이고, 스킨이 레벨 입력을 제공하기 시작하면
     * 그대로 구멍이 된다. 애매하면 상속(null)으로 떨어지는 것이 계약이다.
     */
    public function testAmbiguousInputFallsBackToInheritNotZero(): void
    {
        $inherit = [null, '', ' ', 'abc', '-1', '11', '1.5', true, []];

        foreach ($inherit as $raw) {
            $this->assertNull(
                $this->normalizeLevelLikeService($raw),
                sprintf('%s 는 상속(null)이어야 한다', var_export($raw, true))
            );
        }

        $this->assertSame(0, $this->normalizeLevelLikeService('0'), '"0" 은 명시적 전체 공개');
        $this->assertSame(3, $this->normalizeLevelLikeService('3'));
        $this->assertSame(3, $this->normalizeLevelLikeService(3));
    }

    /** BoardArticleService::resolveLevel 의 입력 판정과 같은 규칙 (권한 게이트 이전 단계) */
    private function normalizeLevelLikeService(mixed $raw): ?int
    {
        if (!is_int($raw) && !(is_string($raw) && $raw !== '' && ctype_digit($raw))) {
            return null;
        }

        $level = (int) $raw;

        return ($level < 0 || $level > 10) ? null : $level;
    }

    private function board(array $overrides = []): BoardConfig
    {
        return BoardConfig::fromArray(array_merge([
            'board_id' => 1,
            'domain_id' => 1,
            'group_id' => 1,
            'board_slug' => 'notice',
            'list_level' => 0,
            'read_level' => 0,
            'write_level' => 1,
            'comment_level' => 1,
            'download_level' => 0,
            'use_file' => 1,
            'board_admin_ids' => '[]',
        ], $overrides));
    }

    private function context(bool $isAdmin = false): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('isAdmin')->willReturn($isAdmin);

        return $context;
    }

    private function loginAs(int $levelValue, bool $admin): void
    {
        $user = new AuthenticatedUser(
            memberId: 10,
            domainId: 1,
            userId: 'tester',
            nickname: 'tester',
            levelValue: $levelValue,
            admin: $admin,
            super: false,
            canOperateDomain: false,
            avatar: null,
        );

        $this->authServiceMock->method('currentUser')->willReturn($user);
        $this->authServiceMock->method('id')->willReturn(10);
        $this->authServiceMock->method('guest')->willReturn(false);
        // 게시판 관리자 판정은 authService 의 사이트 관리자 여부를 먼저 본다
        $this->authServiceMock->method('isAdmin')->willReturn($admin);
        $this->authServiceMock->method('isSuper')->willReturn(false);
    }
}
