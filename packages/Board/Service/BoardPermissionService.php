<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Service;

use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardPermission;
use Mublo\Packages\Board\Entity\BoardCategoryMapping;
use Mublo\Packages\Board\Enum\PermissionTargetType;
use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardPermissionRepository;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Result\Result;

/**
 * BoardPermissionService
 *
 * 게시판 권한 체크 및 관리자 권한 관리 서비스
 *
 * 권한 우선순위: 게시글 → 카테고리매핑 → 게시판 → 그룹
 * canAccess = memberLevel >= requiredLevel
 *
 * 특수 케이스:
 * - 본인 글: canModify, canDelete 허용
 * - 관리자: board_permissions 테이블 기반 권한 체크
 * - 비밀글: 작성자 또는 관리자만 읽기 가능
 */
class BoardPermissionService
{
    private BoardGroupRepository $groupRepository;
    private BoardCategoryMappingRepository $mappingRepository;
    private BoardPermissionRepository $permissionRepository;
    private AuthContextInterface $authService;

    /** 요청 스코프 캐시: isAdmin 결과 [boardId => bool] */
    private array $adminCache = [];

    public function __construct(
        BoardGroupRepository $groupRepository,
        BoardCategoryMappingRepository $mappingRepository,
        BoardPermissionRepository $permissionRepository,
        AuthContextInterface $authService
    ) {
        $this->groupRepository = $groupRepository;
        $this->mappingRepository = $mappingRepository;
        $this->permissionRepository = $permissionRepository;
        $this->authService = $authService;
    }

    /**
     * 현재 로그인 사용자 ID 조회
     */
    private function getCurrentUserId(): ?int
    {
        return $this->authService->id();
    }

    /**
     * 목록 보기 권한 체크
     */
    public function canList(BoardConfig $board, Context $context): bool
    {
        // 관리자는 모두 허용
        if ($this->isAdmin($board, $context)) {
            return true;
        }

        $requiredLevel = $this->getRequiredLevel($board, null, 'list');
        $memberLevel = $this->getMemberLevel($context);

        return $memberLevel >= $requiredLevel;
    }

    /**
     * 글 읽기 권한 체크
     */
    public function canRead(BoardConfig $board, ?BoardArticle $article, Context $context): bool
    {
        // 관리자는 모두 허용
        if ($this->isAdmin($board, $context)) {
            return true;
        }

        // 비밀글 체크
        if ($article && $article->isSecret()) {
            return $this->canReadSecret($article, $context);
        }

        $requiredLevel = $this->getRequiredLevel($board, $article, 'read');
        $memberLevel = $this->getMemberLevel($context);

        return $memberLevel >= $requiredLevel;
    }

    /**
     * 글쓰기 권한 체크
     */
    public function canWrite(BoardConfig $board, Context $context, ?int $categoryId = null): bool
    {
        $categoryMapping = $categoryId !== null
            ? $this->findAvailableCategoryMapping($board, $categoryId)
            : null;
        if ($categoryId !== null && $categoryMapping === null) {
            return false;
        }

        // 관리자는 모두 허용
        if ($this->isAdmin($board, $context)) {
            return true;
        }

        $requiredLevel = $this->getRequiredLevelWithCategory($board, $categoryId, 'write', $categoryMapping);
        $memberLevel = $this->getMemberLevel($context); // 비회원은 0

        // write_level이 0이면 비회원도 허용
        return $memberLevel >= $requiredLevel;
    }

    /**
     * 댓글 쓰기 권한 체크
     */
    public function canComment(BoardConfig $board, Context $context, ?int $categoryId = null): bool
    {
        // 댓글 기능 사용 여부 체크
        if (!$board->isUseComment()) {
            return false;
        }

        $categoryMapping = $categoryId !== null
            ? $this->findAvailableCategoryMapping($board, $categoryId)
            : null;
        if ($categoryId !== null && $categoryMapping === null) {
            return false;
        }

        // 관리자는 모두 허용
        if ($this->isAdmin($board, $context)) {
            return true;
        }

        // 비회원 댓글 허용 체크 (글쓰기와 동일하게 처리)
        if ($this->authService->guest() && !$board->isAllowGuest()) {
            return false;
        }

        $requiredLevel = $this->getRequiredLevelWithCategory($board, $categoryId, 'comment', $categoryMapping);
        $memberLevel = $this->getMemberLevel($context);

        return $memberLevel >= $requiredLevel;
    }

    /**
     * 카테고리가 해당 게시판에 활성 상태로 매핑되어 있는지 확인한다.
     *
     * 카테고리를 사용하지 않는 게시판에 카테고리 ID를 주입하거나,
     * 다른 게시판의 카테고리를 저장하는 것을 막는 저장 경계 검증이다.
     */
    public function isCategoryAvailable(BoardConfig $board, ?int $categoryId): bool
    {
        if ($categoryId === null) {
            return true;
        }

        return $this->findAvailableCategoryMapping($board, $categoryId) !== null;
    }

    /**
     * 다운로드 권한 체크
     */
    public function canDownload(BoardConfig $board, ?BoardArticle $article, Context $context): bool
    {
        // 파일 첨부 기능 사용 여부 체크
        if (!$board->isUseFile()) {
            return false;
        }

        // 관리자는 모두 허용
        if ($this->isAdmin($board, $context)) {
            return true;
        }

        $requiredLevel = $this->getRequiredLevel($board, $article, 'download');
        $memberLevel = $this->getMemberLevel($context);

        return $memberLevel >= $requiredLevel;
    }

    /**
     * 수정 권한 체크
     */
    public function canModify(BoardConfig $board, BoardArticle $article, Context $context): bool
    {
        // 관리자는 모두 허용
        if ($this->isAdmin($board, $context)) {
            return true;
        }

        $userId = $this->getCurrentUserId();

        // 회원 작성 글
        if ($article->isMemberArticle()) {
            return $userId !== null && $article->isAuthor($userId);
        }

        // 비회원 작성 글은 비밀번호 확인 필요 (Controller에서 처리)
        return false;
    }

    /**
     * 삭제 권한 체크
     */
    public function canDelete(BoardConfig $board, BoardArticle $article, Context $context): bool
    {
        // 수정 권한과 동일
        return $this->canModify($board, $article, $context);
    }

    /**
     * 공지사항 작성 권한 체크
     */
    public function canWriteNotice(BoardConfig $board, Context $context): bool
    {
        return $this->isAdmin($board, $context);
    }

    /**
     * 반응 권한 체크
     */
    public function canReact(BoardConfig $board, Context $context): bool
    {
        // 반응 기능 사용 여부 체크
        if (!$board->isUseReaction()) {
            return false;
        }

        // 로그인 필수
        return $this->authService->check();
    }

    // === Private Methods ===

    /**
     * 관리자 여부 확인
     *
     * 관리자 조건:
     * 1. 시스템 관리자 (is_super)
     * 2. 사이트 관리자 (is_admin)
     * 3. 그룹 관리자 (board_permissions 테이블)
     * 4. 게시판 관리자 (board_permissions 테이블)
     */
    private function isAdmin(BoardConfig $board, Context $context): bool
    {
        $boardId = $board->getBoardId();

        if (array_key_exists($boardId, $this->adminCache)) {
            return $this->adminCache[$boardId];
        }

        $result = $this->resolveIsAdmin($board);
        $this->adminCache[$boardId] = $result;

        return $result;
    }

    /**
     * 관리자 여부 실제 판별 (DB 조회)
     */
    private function resolveIsAdmin(BoardConfig $board): bool
    {
        $memberId = $this->getCurrentUserId();
        if ($memberId === null) {
            return false;
        }

        // 시스템/사이트 관리자
        if ($this->authService->isSuper() || $this->authService->isAdmin()) {
            return true;
        }

        // 게시판 관리자 (board_permissions 테이블 조회)
        if ($this->permissionRepository->isBoardAdmin($board->getBoardId(), $memberId)) {
            return true;
        }

        // 그룹 관리자 (board_permissions 테이블 조회)
        if ($this->permissionRepository->isGroupAdmin($board->getGroupId(), $memberId)) {
            return true;
        }

        return false;
    }

    /**
     * 비밀글 읽기 권한 체크
     */
    private function canReadSecret(BoardArticle $article, Context $context): bool
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return false;
        }

        // 본인 글
        if ($article->isAuthor($userId)) {
            return true;
        }

        return false;
    }

    /**
     * 필요 레벨 조회 (게시글 → 카테고리 → 게시판 → 그룹 순서)
     */
    private function getRequiredLevel(BoardConfig $board, ?BoardArticle $article, string $type): int
    {
        // 1. 게시글에 개별 레벨 설정이 있으면 사용
        if ($article) {
            $articleLevel = match ($type) {
                'read' => $article->getReadLevel(),
                'download' => $article->getDownloadLevel(),
                default => null,
            };

            if ($articleLevel !== null) {
                return $articleLevel;
            }

            // 2. 카테고리 매핑에 레벨 오버라이드가 있으면 사용
            $categoryId = $article->getCategoryId();
            if ($categoryId) {
                $mapping = $this->mappingRepository->findByBoardAndCategory($board->getBoardId(), $categoryId);
                if ($mapping) {
                    $categoryLevel = $mapping->getLevel($type);
                    if ($categoryLevel !== null) {
                        return $categoryLevel;
                    }
                }
            }
        }

        // 3. 게시판 설정의 레벨 사용
        $boardLevel = match ($type) {
            'list' => $board->getListLevel(),
            'read' => $board->getReadLevel(),
            'write' => $board->getWriteLevel(),
            'comment' => $board->getCommentLevel(),
            'download' => $board->getDownloadLevel(),
            default => 0,
        };

        if ($boardLevel !== null) {
            return $boardLevel;
        }

        // 4. 그룹 설정의 레벨 사용
        $group = $this->groupRepository->find($board->getGroupId());
        if ($group) {
            return match ($type) {
                'list' => $group->getListLevel(),
                'read' => $group->getReadLevel(),
                'write' => $group->getWriteLevel(),
                'comment' => $group->getCommentLevel(),
                'download' => $group->getDownloadLevel(),
                default => 0,
            };
        }

        return 0;
    }

    /**
     * 카테고리 포함 필요 레벨 조회 (글쓰기/댓글용)
     */
    private function getRequiredLevelWithCategory(
        BoardConfig $board,
        ?int $categoryId,
        string $type,
        ?BoardCategoryMapping $categoryMapping = null
    ): int
    {
        // 카테고리 매핑 레벨 체크
        if ($categoryId) {
            $mapping = $categoryMapping
                ?? $this->findAvailableCategoryMapping($board, $categoryId);
            if ($mapping) {
                $categoryLevel = $mapping->getLevel($type);
                if ($categoryLevel !== null) {
                    return $categoryLevel;
                }
            }
        }

        // 게시판 레벨
        $boardLevel = match ($type) {
            'write' => $board->getWriteLevel(),
            'comment' => $board->getCommentLevel(),
            default => 0,
        };

        if ($boardLevel !== null) {
            return $boardLevel;
        }

        // 그룹 레벨
        $group = $this->groupRepository->find($board->getGroupId());
        if ($group) {
            return match ($type) {
                'write' => $group->getWriteLevel(),
                'comment' => $group->getCommentLevel(),
                default => 0,
            };
        }

        return 0;
    }

    private function findAvailableCategoryMapping(
        BoardConfig $board,
        int $categoryId
    ): ?BoardCategoryMapping
    {
        if ($categoryId <= 0 || !$board->useCategory()) {
            return null;
        }

        $mapping = $this->mappingRepository->findByBoardAndCategory($board->getBoardId(), $categoryId);

        return $mapping !== null && $mapping->isActive() ? $mapping : null;
    }

    /**
     * 현재 회원 레벨 조회
     */
    private function getMemberLevel(Context $context): int
    {
        return $this->authService->currentUser()?->levelValue ?? 0;
    }

    // ========================================
    // 관리자 권한 관리 메서드 (board_permissions 테이블)
    // ========================================

    /**
     * 그룹 관리자 여부 확인
     */
    public function isGroupAdmin(int $groupId, int $memberId): bool
    {
        return $this->permissionRepository->isGroupAdmin($groupId, $memberId);
    }

    /**
     * 카테고리 관리자 여부 확인
     */
    public function isCategoryAdmin(int $categoryId, int $memberId): bool
    {
        return $this->permissionRepository->isCategoryAdmin($categoryId, $memberId);
    }

    /**
     * 게시판 관리자 여부 확인
     */
    public function isBoardAdmin(int $boardId, int $memberId): bool
    {
        return $this->permissionRepository->isBoardAdmin($boardId, $memberId);
    }

    /**
     * 그룹의 관리자 회원 ID 목록 조회
     *
     * @return int[]
     */
    public function getGroupAdminIds(int $groupId): array
    {
        return $this->permissionRepository->getAdminMemberIds(PermissionTargetType::GROUP->value, $groupId);
    }

    /**
     * 카테고리의 관리자 회원 ID 목록 조회
     *
     * @return int[]
     */
    public function getCategoryAdminIds(int $categoryId): array
    {
        return $this->permissionRepository->getAdminMemberIds(PermissionTargetType::CATEGORY->value, $categoryId);
    }

    /**
     * 게시판의 관리자 회원 ID 목록 조회
     *
     * @return int[]
     */
    public function getBoardAdminIds(int $boardId): array
    {
        return $this->permissionRepository->getAdminMemberIds(PermissionTargetType::BOARD->value, $boardId);
    }

    /**
     * 그룹의 관리자 목록 (회원 정보 포함)
     *
     * @return array [['permission_id' => ..., 'member_id' => ..., 'userid' => ..., 'name' => ...], ...]
     */
    public function getGroupAdminsWithInfo(int $groupId): array
    {
        return $this->permissionRepository->findAdminsWithMemberInfo(PermissionTargetType::GROUP->value, $groupId);
    }

    /**
     * 카테고리의 관리자 목록 (회원 정보 포함)
     */
    public function getCategoryAdminsWithInfo(int $categoryId): array
    {
        return $this->permissionRepository->findAdminsWithMemberInfo(PermissionTargetType::CATEGORY->value, $categoryId);
    }

    /**
     * 게시판의 관리자 목록 (회원 정보 포함)
     */
    public function getBoardAdminsWithInfo(int $boardId): array
    {
        return $this->permissionRepository->findAdminsWithMemberInfo(PermissionTargetType::BOARD->value, $boardId);
    }

    /**
     * 그룹 관리자 추가
     */
    public function addGroupAdmin(int $domainId, int $groupId, int $memberId): Result
    {
        $permissionId = $this->permissionRepository->grantPermission(
            $domainId,
            PermissionTargetType::GROUP->value,
            $groupId,
            $memberId
        );

        if ($permissionId) {
            return Result::success('그룹 관리자가 추가되었습니다.', ['permission_id' => $permissionId]);
        }

        return Result::failure('이미 등록된 관리자입니다.');
    }

    /**
     * 그룹 관리자 제거
     */
    public function removeGroupAdmin(int $groupId, int $memberId): Result
    {
        $affected = $this->permissionRepository->revokePermission(
            PermissionTargetType::GROUP->value,
            $groupId,
            $memberId
        );

        if ($affected > 0) {
            return Result::success('그룹 관리자가 제거되었습니다.');
        }

        return Result::failure('관리자 제거에 실패했습니다.');
    }

    /**
     * 게시판 관리자 추가
     */
    public function addBoardAdmin(int $domainId, int $boardId, int $memberId): Result
    {
        $permissionId = $this->permissionRepository->grantPermission(
            $domainId,
            PermissionTargetType::BOARD->value,
            $boardId,
            $memberId
        );

        if ($permissionId) {
            return Result::success('게시판 관리자가 추가되었습니다.', ['permission_id' => $permissionId]);
        }

        return Result::failure('이미 등록된 관리자입니다.');
    }

    /**
     * 게시판 관리자 제거
     */
    public function removeBoardAdmin(int $boardId, int $memberId): Result
    {
        $affected = $this->permissionRepository->revokePermission(
            PermissionTargetType::BOARD->value,
            $boardId,
            $memberId
        );

        if ($affected > 0) {
            return Result::success('게시판 관리자가 제거되었습니다.');
        }

        return Result::failure('관리자 제거에 실패했습니다.');
    }

    /**
     * 그룹 관리자 일괄 설정 (기존 모두 삭제 후 새로 설정)
     *
     * @param int[] $memberIds 관리자 회원 ID 배열
     */
    public function setGroupAdmins(int $domainId, int $groupId, array $memberIds): Result
    {
        $this->permissionRepository->setAdmins(
            $domainId,
            PermissionTargetType::GROUP->value,
            $groupId,
            $memberIds
        );

        return Result::success('그룹 관리자가 설정되었습니다.');
    }

    /**
     * 게시판 관리자 일괄 설정
     *
     * @param int[] $memberIds 관리자 회원 ID 배열
     */
    public function setBoardAdmins(int $domainId, int $boardId, array $memberIds): Result
    {
        $this->permissionRepository->setAdmins(
            $domainId,
            PermissionTargetType::BOARD->value,
            $boardId,
            $memberIds
        );

        return Result::success('게시판 관리자가 설정되었습니다.');
    }

    /**
     * 그룹 삭제 시 관련 권한 모두 삭제
     */
    public function deleteGroupPermissions(int $groupId): int
    {
        return $this->permissionRepository->revokeAllByTarget(PermissionTargetType::GROUP->value, $groupId);
    }

    /**
     * 게시판 삭제 시 관련 권한 모두 삭제
     */
    public function deleteBoardPermissions(int $boardId): int
    {
        return $this->permissionRepository->revokeAllByTarget(PermissionTargetType::BOARD->value, $boardId);
    }

    /**
     * 회원 삭제 시 관련 권한 모두 삭제
     */
    public function deleteMemberPermissions(int $memberId): int
    {
        return $this->permissionRepository->revokeAllByMember($memberId);
    }
}
