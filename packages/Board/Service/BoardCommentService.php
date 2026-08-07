<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Service;

use Mublo\Packages\Board\Entity\BoardComment;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Contract\Member\MemberIdentity;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Core\Context\Context;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Packages\Board\Event\CommentCreatingEvent;
use Mublo\Packages\Board\Event\CommentCreatedEvent;
use Mublo\Packages\Board\Event\CommentUpdatedEvent;
use Mublo\Packages\Board\Event\CommentDeletedEvent;
use Mublo\Core\Result\Result;

/**
 * BoardCommentService
 *
 * 댓글 비즈니스 로직 + 이벤트 발행
 */
class BoardCommentService
{
    private const CONTENT_MAX_CHARACTERS = 16000;
    private const CONTENT_MAX_BYTES = 65535;
    private const AUTHOR_NAME_MAX_LENGTH = 50;
    private const PASSWORD_MAX_BYTES = 4096;

    private BoardCommentRepository $commentRepository;
    private BoardArticleRepository $articleRepository;
    private BoardConfigRepository $boardRepository;
    private MemberQueryInterface $memberRepository;
    private BoardPermissionService $permissionService;
    private ?EventDispatcher $eventDispatcher;
    private AuthContextInterface $authService;

    public function __construct(
        BoardCommentRepository $commentRepository,
        BoardArticleRepository $articleRepository,
        BoardConfigRepository $boardRepository,
        MemberQueryInterface $memberRepository,
        BoardPermissionService $permissionService,
        ?EventDispatcher $eventDispatcher,
        AuthContextInterface $authService
    ) {
        $this->commentRepository = $commentRepository;
        $this->articleRepository = $articleRepository;
        $this->boardRepository = $boardRepository;
        $this->memberRepository = $memberRepository;
        $this->permissionService = $permissionService;
        $this->eventDispatcher = $eventDispatcher;
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
     * 이벤트 발행 헬퍼
     *
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    /**
     * 게시글 댓글 목록 조회
     */
    public function getCommentsByArticle(int $articleId): array
    {
        return $this->commentRepository->getCommentsWithAuthor($articleId);
    }

    /**
     * 댓글 작성
     */
    public function create(int $articleId, array $data, Context $context): Result
    {
        // 게시글 조회
        $article = $this->articleRepository->find($articleId);
        if (!$article) {
            return Result::failure('게시글을 찾을 수 없습니다.');
        }

        // 게시판 조회
        $board = $this->boardRepository->find($article->getBoardId());
        if (!$board) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }

        // 도메인 경계 + 읽기 권한 필수: 못 읽는 글(비밀글·등급제한)에는 댓글을 달 수 없다.
        // 전역 게시판은 모든 도메인에서 동일하게 댓글을 작성할 수 있다.
        // canComment는 article을 안 받아 비밀/읽기권한을 못 보므로, 여기서 canRead를 먼저 강제한다.
        $accessDomainId = $context->getDomainId() ?? 0;
        if (!$board->isGlobal() && $article->getDomainId() !== $accessDomainId) {
            return Result::failure('권한이 없는 게시글입니다.');
        }
        if (!$this->permissionService->canRead($board, $article, $context)) {
            return Result::failure('댓글 작성 권한이 없습니다.');
        }

        // 권한 체크
        if (!$this->permissionService->canComment($board, $context, $article->getCategoryId())) {
            return Result::failure('댓글 작성 권한이 없습니다.');
        }

        $memberId = $this->getCurrentUserId();
        // 댓글 활동·포인트·마이페이지는 댓글을 작성한 현재 도메인에 귀속한다.
        $domainId = $accessDomainId;
        $boardId = $article->getBoardId();

        // 레벨별 1일 댓글 제한 체크
        //
        // 비회원(레벨 0)도 대상이다. 비회원 댓글을 연 게시판에서 한도를 걸지
        // 못하면 설정이 있으나 마나가 된다. 식별자가 IP 뿐이라 공유 IP 뒤의
        // 여러 사람이 한도를 나눠 쓰지만, 아예 못 거는 것보다는 낫다.
        $memberLevel = $this->authService->currentUser()?->levelValue ?? 0;
        $commentLimit = $board->getDailyCommentLimitForLevel($memberLevel);
        if ($commentLimit !== null) {
            $todayCount = $memberId !== null
                ? $this->commentRepository->countTodayByMember($boardId, $memberId)
                : $this->commentRepository->countTodayByIp($boardId, (string) $context->getRequest()->getClientIp());

            if ($todayCount >= $commentLimit) {
                return Result::failure('1일 댓글 제한(' . $commentLimit . '회)을 초과했습니다.');
            }
        }

        // 작성자 정보 설정
        if ($memberId !== null) {
            // 회원: 공개 가능한 표시명을 author_name 스냅샷으로 저장
            $member = $this->memberRepository->findProfile($memberId);
            $data['author_name'] = $member?->publicDisplayName() ?? '회원';
            $data['author_password'] = null;
        } else {
            // 비회원: 입력값 검증
            if (!is_string($data['author_name'] ?? null)) {
                return Result::failure('이름을 올바른 형식으로 입력해주세요.');
            }
            $data['author_name'] = trim(strip_tags($data['author_name']));
            if ($data['author_name'] === '') {
                return Result::failure('이름을 입력해주세요.');
            }
            if (mb_strlen($data['author_name'], 'UTF-8') > self::AUTHOR_NAME_MAX_LENGTH) {
                return Result::failure('작성자 이름은 50자 이하로 입력해주세요.');
            }
            if (!is_string($data['author_password'] ?? null) || $data['author_password'] === '') {
                return Result::failure('비밀번호를 입력해주세요.');
            }
            if (strlen($data['author_password']) > self::PASSWORD_MAX_BYTES) {
                return Result::failure('비밀번호가 너무 깁니다.');
            }
            $data['author_password'] = password_hash($data['author_password'], PASSWORD_DEFAULT);
        }

        // 1. Creating 이벤트 발행 (차단 가능)
        $creatingEvent = new CommentCreatingEvent($domainId, $boardId, $articleId, $data, $memberId);
        $this->dispatch($creatingEvent);

        if ($creatingEvent->isBlocked()) {
            return Result::failure($creatingEvent->getBlockReason() ?? '댓글 작성이 차단되었습니다.');
        }

        // 이벤트에서 수정된 데이터 사용
        $data = $creatingEvent->getData();

        $contentResult = $this->normalizeAndValidateContent($data['content'] ?? null);
        if ($contentResult['error'] !== null) {
            return Result::failure($contentResult['error']);
        }
        $data['content'] = $contentResult['content'];

        if (!is_string($data['author_name'] ?? null)) {
            return Result::failure('작성자 이름을 올바른 형식으로 입력해주세요.');
        }
        $data['author_name'] = trim(strip_tags($data['author_name']));
        if ($data['author_name'] === '' || mb_strlen($data['author_name'], 'UTF-8') > self::AUTHOR_NAME_MAX_LENGTH) {
            return Result::failure('작성자 이름은 1자 이상 50자 이하로 입력해주세요.');
        }
        if (array_key_exists('author_password', $data)
            && $data['author_password'] !== null
            && !is_string($data['author_password'])
        ) {
            return Result::failure('작성자 비밀번호 형식이 올바르지 않습니다.');
        }

        // 부모 댓글 처리 (대댓글)
        $parentId = $this->normalizeParentId($data['parent_id'] ?? null);
        if ($parentId === false) {
            return Result::failure('유효하지 않은 부모 댓글입니다.');
        }
        if ($parentId !== null) {
            $parent = $this->commentRepository->find($parentId);
            if (
                !$parent
                || !$parent->isPublished()
                || $parent->getArticleId() !== $articleId
                || $parent->getBoardId() !== $boardId
                || (!$board->isGlobal() && $parent->getDomainId() !== $domainId)
            ) {
                return Result::failure('유효하지 않은 부모 댓글입니다.');
            }
        }

        $path = $this->commentRepository->generatePath($articleId, $parentId);
        $depth = $this->commentRepository->calculateDepth($parentId);

        // 데이터 정규화
        $insertData = [
            'domain_id' => $domainId,
            'board_id' => $boardId,
            'article_id' => $articleId,
            'parent_id' => $parentId,
            'member_id' => $memberId,
            'author_name' => $data['author_name'] ?? null,
            'author_password' => $data['author_password'] ?? null,
            'content' => $data['content'],
            'is_secret' => (int) !empty($data['is_secret']),
            'status' => 'published',
            'depth' => $depth,
            'path' => $path,
            'ip_address' => $context->getRequest()->getClientIp(),
        ];

        // 2. 댓글 저장과 파생 카운트 동기화는 하나의 원자적 변경이다.
        try {
            $commentId = $this->commentRepository->getDb()->transaction(function () use ($insertData, $articleId): int {
                $commentId = $this->commentRepository->create($insertData);
                if (!$commentId) {
                    throw new \RuntimeException('댓글 저장 실패');
                }
                $this->articleRepository->syncCommentCount($articleId);

                return $commentId;
            });
        } catch (\Throwable $e) {
            error_log('[BoardCommentService::create] ' . $e->getMessage());
            return Result::failure('댓글 저장에 실패했습니다.');
        }

        // 저장된 댓글 조회
        $comment = $this->commentRepository->find($commentId);

        // 3. Created 이벤트 발행
        $author = $memberId ? $this->identity($this->memberRepository->findProfile($memberId)) : null;
        $this->dispatch(new CommentCreatedEvent($comment, $article, $author));

        return Result::success('댓글이 작성되었습니다.', ['comment_id' => $commentId]);
    }

    /**
     * 댓글 수정
     */
    public function update(int $commentId, array $data, Context $context, ?string $guestPassword = null): Result
    {
        // 댓글 조회
        $comment = $this->commentRepository->find($commentId);
        if (!$comment) {
            return Result::failure('댓글을 찾을 수 없습니다.');
        }

        if (!$this->isCommentAccessibleInDomain($comment, $context)) {
            return Result::failure('수정 권한이 없습니다.');
        }

        // 권한 체크
        if (!$this->canModifyComment($comment, $context, $guestPassword)) {
            return Result::failure('수정 권한이 없습니다.');
        }

        $contentResult = $this->normalizeAndValidateContent($data['content'] ?? null);
        if ($contentResult['error'] !== null) {
            return Result::failure($contentResult['error']);
        }

        $oldData = $comment->toArray();
        $updateData = [
            'content' => $contentResult['content'],
        ];
        // 부분 수정: 비밀 여부가 요청에 없으면 기존 값을 보존한다.
        if (array_key_exists('is_secret', $data)) {
            $updateData['is_secret'] = (bool) $data['is_secret'];
        }

        $dbResult = $this->commentRepository->update($commentId, $updateData);
        if (!$dbResult) {
            return Result::failure('댓글 수정에 실패했습니다.');
        }

        $updatedComment = $this->commentRepository->find($commentId);
        if ($updatedComment) {
            $this->dispatch(new CommentUpdatedEvent(
                $updatedComment,
                $oldData,
                $this->getCurrentUserId()
            ));
        }

        return Result::success('댓글이 수정되었습니다.');
    }

    /**
     * 댓글 삭제
     */
    public function delete(int $commentId, Context $context, ?string $guestPassword = null): Result
    {
        // 댓글 조회
        $comment = $this->commentRepository->find($commentId);
        if (!$comment) {
            return Result::failure('댓글을 찾을 수 없습니다.');
        }

        if (!$this->isCommentAccessibleInDomain($comment, $context)) {
            return Result::failure('삭제 권한이 없습니다.');
        }

        // 권한 체크
        if (!$this->canModifyComment($comment, $context, $guestPassword)) {
            return Result::failure('삭제 권한이 없습니다.');
        }

        $memberId = $this->getCurrentUserId();
        $articleId = $comment->getArticleId();

        // 대댓글 삭제와 파생 카운트 동기화가 서로 어긋나지 않도록 같은 트랜잭션에서 처리한다.
        try {
            $deleted = $this->commentRepository->getDb()->transaction(function () use ($commentId, $articleId): int {
                $deleted = $this->commentRepository->deleteWithChildren($commentId);
                if ($deleted < 1) {
                    throw new \RuntimeException('댓글 삭제 실패');
                }
                $this->articleRepository->syncCommentCount($articleId);

                return $deleted;
            });
        } catch (\Throwable $e) {
            error_log('[BoardCommentService::delete] ' . $e->getMessage());
            return Result::failure('댓글 삭제에 실패했습니다.');
        }

        // Deleted 이벤트 발행
        $this->dispatch(new CommentDeletedEvent($comment, $memberId));

        return Result::success('댓글이 삭제되었습니다.', ['deleted_count' => $deleted]);
    }

    /**
     * 비회원 비밀번호 확인
     */
    public function verifyGuestPassword(int $commentId, string $password): bool
    {
        $comment = $this->commentRepository->find($commentId);
        if (!$comment || $comment->isMemberComment()) {
            return false;
        }

        $storedPassword = $comment->getAuthorPassword();
        if (!$storedPassword) {
            return false;
        }

        return password_verify($password, $storedPassword);
    }

    /**
     * 최근 댓글 조회 (Admin용)
     */
    public function getRecentComments(int $domainId, int $limit = 10): array
    {
        return $this->commentRepository->getRecentComments($domainId, $limit);
    }

    /**
     * 회원별 댓글 수 조회
     */
    public function countByMember(int $memberId): int
    {
        return $this->commentRepository->countByMember($memberId);
    }

    // === Private Methods ===

    /**
     * 댓글은 HTML이 아닌 평문으로 저장한다. HTML 이스케이프는 출력 경계의 책임이다.
     *
     * @return array{content:string, error:?string}
     */
    private function normalizeAndValidateContent(mixed $value): array
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return ['content' => '', 'error' => '댓글 내용을 올바른 형식으로 입력해주세요.'];
        }

        $content = str_replace(["\r\n", "\r"], "\n", $value);
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content);
        if ($content === null) {
            return ['content' => '', 'error' => '댓글 내용을 올바른 형식으로 입력해주세요.'];
        }

        $content = trim($content);
        if ($content === '') {
            return ['content' => '', 'error' => '댓글 내용을 입력해주세요.'];
        }
        if (mb_strlen($content, 'UTF-8') > self::CONTENT_MAX_CHARACTERS
            || strlen($content) > self::CONTENT_MAX_BYTES
        ) {
            return ['content' => '', 'error' => '댓글 내용이 너무 깁니다.'];
        }

        return ['content' => $content, 'error' => null];
    }

    /** @return int|false|null false는 잘못된 입력을 의미한다. */
    private function normalizeParentId(mixed $value): int|false|null
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : false;
        }
        if (is_string($value) && ctype_digit($value)) {
            $parentId = (int) $value;
            return $parentId > 0 ? $parentId : false;
        }

        return false;
    }

    /**
     * 댓글 수정/삭제 권한 체크
     */
    private function canModifyComment(BoardComment $comment, Context $context, ?string $guestPassword = null): bool
    {
        $userId = $this->getCurrentUserId();

        // 회원 작성 댓글
        if ($comment->isMemberComment()) {
            if ($userId === null) {
                return false;
            }

            // 본인 댓글
            if ($userId !== null && $comment->isAuthor($userId)) {
                return true;
            }

            // 관리자
            if ($this->authService->isSuper() || $this->authService->isAdmin()) {
                return true;
            }

            // 게시판 관리자 체크
            $board = $this->boardRepository->find($comment->getBoardId());
            if ($board && $userId !== null) {
                $boardAdminIds = $board->getBoardAdminIds();
                if (!empty($boardAdminIds) && in_array($userId, $boardAdminIds, true)) {
                    return true;
                }
            }

            return false;
        }

        // 비회원 작성 댓글
        // 관리자는 비밀번호 없이 수정/삭제 가능
        if ($this->authService->isSuper() || $this->authService->isAdmin()) {
            return true;
        }

        // 비회원은 비밀번호 확인 필요
        if ($guestPassword !== null) {
            return $this->verifyGuestPassword($comment->getCommentId(), $guestPassword);
        }

        return false;
    }

    private function isCommentAccessibleInDomain(BoardComment $comment, Context $context): bool
    {
        $board = $this->boardRepository->find($comment->getBoardId());
        if (!$board) {
            return false;
        }

        return $board->isGlobal()
            || $comment->getDomainId() === ($context->getDomainId() ?? 0);
    }

    private function identity(?MemberProfile $profile): ?MemberIdentity
    {
        return $profile === null ? null : new MemberIdentity(
            $profile->memberId,
            $profile->domainId,
            $profile->userId,
            $profile->publicDisplayName(),
            $profile->publicId,
        );
    }
}
