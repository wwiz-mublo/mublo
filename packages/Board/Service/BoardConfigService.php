<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Service;

use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Board\Event\BoardConfigCreatedEvent;
use Mublo\Packages\Board\Event\BoardConfigDeletedEvent;
use Mublo\Helper\Directory\DirectoryHelper;
use Mublo\Helper\Form\FormHelper;
use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationFingerprint;
use Mublo\Contract\Site\SiteMutationRecorderInterface;
use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Contract\Board\BoardReadOverlayInterface;

/**
 * BoardConfig Service
 *
 * 게시판 설정 비즈니스 로직 담당
 *
 * 책임:
 * - 게시판 CRUD 비즈니스 로직
 * - 유효성 검증
 * - 정렬 순서 관리
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class BoardConfigService
{
    private BoardConfigRepository $repository;
    private BoardGroupRepository $groupRepository;
    private BoardCategoryMappingRepository $categoryMappingRepository;
    private BoardArticleRepository $articleRepository;
    private ?EventDispatcher $eventDispatcher;
    private ?AuthContextInterface $authService;
    private ?SiteMutationRecorderInterface $mutationRecorder;
    private ?BoardReadOverlayInterface $readOverlay;

    /**
     * 슬러그 예약어 목록
     */
    private const RESERVED_SLUGS = [
        'admin',
        'api',
        'auth',
        'login',
        'logout',
        'register',
        'member',
        'search',
        'install',
        'setup',
        'create',
        'edit',
        'delete',
        'store',
        'view',
        'list',
    ];

    /**
     * 기본 반응 설정
     */
    private const DEFAULT_REACTION_CONFIG = [
        'like' => [
            'label' => '좋아요',
            'icon' => '👍',
            'color' => '#3B82F6',
            'enabled' => true,
        ],
    ];

    public function __construct(
        BoardConfigRepository $repository,
        BoardGroupRepository $groupRepository,
        BoardCategoryMappingRepository $categoryMappingRepository,
        BoardArticleRepository $articleRepository,
        ?EventDispatcher $eventDispatcher = null,
        ?AuthContextInterface $authService = null,
        ?SiteMutationRecorderInterface $mutationRecorder = null,
        ?BoardReadOverlayInterface $readOverlay = null
    ) {
        $this->repository = $repository;
        $this->groupRepository = $groupRepository;
        $this->categoryMappingRepository = $categoryMappingRepository;
        $this->articleRepository = $articleRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->authService = $authService;
        $this->mutationRecorder = $mutationRecorder;
        $this->readOverlay = $readOverlay;
    }

    private function mutationContext(int $domainId, ?SiteMutationContext $context): ?SiteMutationContext
    {
        if ($context !== null && $context->domainId !== $domainId) {
            throw new \InvalidArgumentException('Site mutation context domain does not match the board domain.');
        }
        if ($context === null && $this->mutationRecorder === null) {
            return null;
        }
        return $context ?? SiteMutationContext::create($domainId, SiteMutationContext::SOURCE_ADMIN);
    }

    private function recordBoardMutation(
        int $domainId,
        int $boardId,
        ?SiteMutationContext $context,
        bool $deleted = false,
        ?string $beforeFingerprint = null
    ): void {
        if ($context === null) {
            return;
        }
        if ($context->domainId !== $domainId) {
            throw new \InvalidArgumentException('Site mutation context domain does not match the board domain.');
        }

        if ($deleted) {
            $fingerprint = SiteMutationFingerprint::hash(['deleted' => true, 'board_id' => $boardId]);
        } else {
            $fingerprint = $this->boardFingerprint($domainId, $boardId);
            if ($beforeFingerprint !== null && $beforeFingerprint === $fingerprint) {
                return;
            }
        }

        $context->changed('board:' . $boardId, 'board', $fingerprint);
    }

    private function boardFingerprint(int $domainId, int $boardId): string
    {
        $board = $this->repository->find($boardId);
        if ($board === null || $board->getDomainId() !== $domainId) {
            throw new \RuntimeException('Changed board could not be read for revision recording.');
        }
        $mappings = array_map(
            static fn (\Mublo\Packages\Board\Entity\BoardCategoryMapping $mapping): array => $mapping->toArray(),
            $this->categoryMappingRepository->findByBoard($boardId, false)
        );
        usort($mappings, static fn (array $left, array $right): int =>
            [(int) ($left['sort_order'] ?? 0), (int) ($left['category_id'] ?? 0)]
            <=> [(int) ($right['sort_order'] ?? 0), (int) ($right['category_id'] ?? 0)]
        );
        return SiteMutationFingerprint::hash([
            'config' => $board->toArray(),
            'category_mappings' => $mappings,
        ]);
    }

    private function finishMutation(?SiteMutationContext $context, bool $ownsContext): void
    {
        if ($ownsContext && $context !== null && !$context->isEmpty() && $this->mutationRecorder !== null) {
            $this->mutationRecorder->record($context);
        }
    }

    /**
     * is_global 플래그는 Super 관리자만 설정 가능
     * Super 가 아닐 경우 0 으로 강제 (뷰 우회/직접 호출 방어)
     */
    private function enforceGlobalFlag(array &$data): void
    {
        if (!array_key_exists('is_global', $data)) {
            return;
        }

        $isSuper = $this->authService?->isSuper() ?? false;
        if (!$isSuper) {
            $data['is_global'] = 0;
        }
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
     * 도메인별 게시판 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardConfig[]
     */
    public function getBoards(int $domainId): array
    {
        return $this->repository->findByDomain($domainId);
    }

    /**
     * 도메인별 게시판 목록 조회 (그룹 정보 포함)
     *
     * @param int $domainId 도메인 ID
     * @return array
     */
    public function getBoardsWithGroup(int $domainId): array
    {
        return $this->repository->findByDomainWithGroup($domainId);
    }

    /**
     * 도메인별 게시판 목록 조회 (게시글 수, 그룹 정보 포함)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @return array ['items' => [...], 'pagination' => [...]]
     */
    public function getBoardsWithArticleCount(int $domainId, int $page = 1, int $perPage = 20): array
    {
        $totalItems = $this->repository->countByDomain($domainId);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $boardsWithGroup = $this->repository->findByDomainWithGroup($domainId, $perPage, $offset);
        $items = [];

        foreach ($boardsWithGroup as $item) {
            $config = $item['config'];
            $boardData = $config->toArray();
            $boardData['group_name'] = $item['group_name'];
            $boardData['group_slug'] = $item['group_slug'];
            $boardData['article_count'] = $this->repository->getArticleCount($config->getBoardId());
            $items[] = $boardData;
        }

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * 게시판의 게시글 수 조회
     *
     * @param int $boardId 게시판 ID
     * @return int
     */
    public function getArticleCount(int $boardId): int
    {
        return $this->repository->getArticleCount($boardId);
    }

    /**
     * 그룹별 게시판 목록 조회
     *
     * @param int $groupId 그룹 ID
     * @return BoardConfig[]
     */
    public function getBoardsByGroup(int $groupId): array
    {
        return $this->repository->findByGroup($groupId);
    }

    /**
     * 도메인별 활성 게시판 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardConfig[]
     */
    public function getActiveBoards(int $domainId): array
    {
        $overlay = $this->readOverlay?->boards($domainId, true);
        if ($overlay !== null) {
            return array_map(fn(BoardDescriptor $board): BoardConfig => $this->fromDescriptor($board), $overlay);
        }
        return $this->repository->findActiveByDomain($domainId);
    }

    /**
     * 단일 게시판 조회
     */
    public function getBoard(int $boardId): ?BoardConfig
    {
        $overlay = $this->readOverlay?->findById($boardId);
        if ($overlay !== null) {
            return $this->fromDescriptor($overlay);
        }
        return $this->repository->find($boardId);
    }

    /**
     * 슬러그로 게시판 조회
     */
    public function getBoardBySlug(int $domainId, string $slug): ?BoardConfig
    {
        if ($this->readOverlay?->boards($domainId) !== null) {
            $overlay = $this->readOverlay->findBySlug($domainId, $slug);
            return $overlay !== null ? $this->fromDescriptor($overlay) : null;
        }
        return $this->repository->findBySlug($domainId, $slug);
    }

    private function fromDescriptor(BoardDescriptor $board): BoardConfig
    {
        return BoardConfig::fromArray($board->values + [
            'board_id' => $board->boardId,
            'board_slug' => $board->slug,
            'board_name' => $board->name,
            'group_id' => $board->groupId,
            'is_active' => $board->active ? 1 : 0,
        ]);
    }

    /**
     * 게시판 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 게시판 데이터
     * @return Result
     */
    public function createBoard(
        int $domainId,
        array $data,
        ?SiteMutationContext $mutationContext = null
    ): Result
    {
        // 필수 필드 검증
        if (empty($data['board_slug']) || empty($data['board_name'])) {
            return Result::failure('필수 필드가 누락되었습니다. (슬러그, 게시판명)');
        }

        if (empty($data['group_id'])) {
            return Result::failure('게시판 그룹을 선택해주세요.');
        }

        // 그룹 존재 확인
        $group = $this->groupRepository->find((int) $data['group_id']);
        if (!$group) {
            return Result::failure('선택한 그룹이 존재하지 않습니다.');
        }

        $slug = $data['board_slug'];

        // 슬러그 유효성 검증
        $slugValidation = $this->validateSlug($slug);
        if ($slugValidation->isFailure()) {
            return $slugValidation;
        }

        // 슬러그 중복 검사
        if ($this->repository->existsBySlug($domainId, $slug)) {
            return Result::failure('이미 사용중인 슬러그입니다.');
        }

        // 반응 설정 검증 (이모지/라벨 필수)
        $reactionError = $this->validateReactionConfig($data);
        if ($reactionError !== null) {
            return Result::failure($reactionError);
        }

        // 다음 정렬 순서
        $sortOrder = $this->repository->getNextSortOrder($domainId);

        // 데이터 정규화
        $insertData = $this->normalizeData($data);
        $this->enforceGlobalFlag($insertData);
        $insertData['domain_id'] = $domainId;
        $insertData['sort_order'] = $sortOrder;

        // 기본 반응 설정 (설정이 없는 경우)
        if (empty($insertData['reaction_config']) && ($insertData['use_reaction'] ?? true)) {
            $insertData['reaction_config'] = json_encode(self::DEFAULT_REACTION_CONFIG, JSON_UNESCAPED_UNICODE);
        }

        $ownsMutationContext = $mutationContext === null;
        $mutationContext = $this->mutationContext($domainId, $mutationContext);
        if ($mutationContext === null) {
            // 레거시 수동 생성 환경은 기존 동작을 유지한다. 운영 DI는 recorder를 주입한다.
            $boardId = $this->repository->create($insertData);
            if (!$boardId) {
                return Result::failure('게시판 생성에 실패했습니다.');
            }
            // 카테고리 매핑 저장
            $categoryIds = $data['category_ids'] ?? [];
            if (!empty($categoryIds) && is_array($categoryIds)) {
                $this->categoryMappingRepository->syncCategories($boardId, $categoryIds);
            }

            // 이벤트 발행 (메뉴 자동 등록 등)
            $boardConfig = $this->repository->find($boardId);
            if ($boardConfig) {
                $this->dispatch(new BoardConfigCreatedEvent($boardConfig));
            }

            return Result::success('게시판이 생성되었습니다.', ['board_id' => $boardId]);
        }

        $boardId = $this->repository->getDb()->transaction(function () use (
            $domainId,
            $data,
            $insertData,
            $mutationContext,
            $ownsMutationContext
        ): int {
            $boardId = $this->repository->create($insertData);
            if (!$boardId) {
                return 0;
            }
            $categoryIds = $data['category_ids'] ?? [];
            if (!empty($categoryIds) && is_array($categoryIds)) {
                $this->categoryMappingRepository->syncCategories($boardId, $categoryIds);
            }

            $boardConfig = $this->repository->find($boardId);
            if ($boardConfig === null) {
                throw new \RuntimeException('Created board could not be read after insertion.');
            }
            $this->recordBoardMutation($domainId, $boardId, $mutationContext);
            $this->finishMutation($mutationContext, $ownsMutationContext);
            $event = new BoardConfigCreatedEvent($boardConfig);
            $this->repository->getDb()->afterCommit(fn () => $this->dispatch($event));
            return $boardId;
        });

        return $boardId > 0
            ? Result::success('게시판이 생성되었습니다.', ['board_id' => $boardId])
            : Result::failure('게시판 생성에 실패했습니다.');
    }

    /**
     * 게시판 수정
     *
     * @param int $boardId 게시판 ID
     * @param array $data 수정 데이터
     * @return Result
     */
    public function updateBoard(
        int $boardId,
        array $data,
        ?SiteMutationContext $mutationContext = null
    ): Result
    {
        $board = $this->repository->find($boardId);

        if (!$board) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }

        // 슬러그 변경 시 유효성 및 중복 검사
        if (!empty($data['board_slug']) && $data['board_slug'] !== $board->getBoardSlug()) {
            $slugValidation = $this->validateSlug($data['board_slug']);
            if ($slugValidation->isFailure()) {
                return $slugValidation;
            }

            if ($this->repository->existsBySlugExceptSelf($board->getDomainId(), $data['board_slug'], $boardId)) {
                return Result::failure('이미 사용중인 슬러그입니다.');
            }
        }

        // 그룹 변경 시 존재 확인
        if (!empty($data['group_id']) && (int) $data['group_id'] !== $board->getGroupId()) {
            $group = $this->groupRepository->find((int) $data['group_id']);
            if (!$group) {
                return Result::failure('선택한 그룹이 존재하지 않습니다.');
            }
        }

        // 일반게시판 → 비밀게시판 전환 시: 게시글이 있으면 차단
        if (!empty($data['is_secret_board']) && !$board->isSecretBoard()) {
            $articleCount = $this->articleRepository->countByBoard($boardId, null);
            if ($articleCount > 0) {
                return Result::failure('게시글이 존재하는 게시판은 비밀게시판으로 전환할 수 없습니다. (기존 공개글의 댓글 등이 노출될 수 있음)');
            }
        }

        // 반응 설정 검증 (이모지/라벨 필수)
        $reactionError = $this->validateReactionConfig($data);
        if ($reactionError !== null) {
            return Result::failure($reactionError);
        }

        // 데이터 정규화
        $updateData = $this->normalizeData($data);
        $this->enforceGlobalFlag($updateData);

        // domain_id, sort_order는 수정 불가
        unset($updateData['domain_id'], $updateData['sort_order']);

        $domainId = $board->getDomainId();
        $ownsMutationContext = $mutationContext === null;
        $mutationContext = $this->mutationContext($domainId, $mutationContext);
        if ($mutationContext === null) {
            $affected = $this->repository->update($boardId, $updateData);
            if ($affected < 0) {
                return Result::failure('게시판 수정에 실패했습니다.');
            }
            // 카테고리 매핑 저장
            $categoryIds = $data['category_ids'] ?? [];
            $this->categoryMappingRepository->syncCategories($boardId, is_array($categoryIds) ? $categoryIds : []);
            return Result::success('게시판이 수정되었습니다.');
        }

        $beforeFingerprint = $this->boardFingerprint($domainId, $boardId);
        $updated = $this->repository->getDb()->transaction(function () use (
            $boardId,
            $data,
            $updateData,
            $domainId,
            $beforeFingerprint,
            $mutationContext,
            $ownsMutationContext
        ): bool {
            $affected = $this->repository->update($boardId, $updateData);
            if ($affected < 0) {
                return false;
            }
            $categoryIds = $data['category_ids'] ?? [];
            $this->categoryMappingRepository->syncCategories(
                $boardId,
                is_array($categoryIds) ? $categoryIds : []
            );
            $this->recordBoardMutation(
                $domainId,
                $boardId,
                $mutationContext,
                false,
                $beforeFingerprint
            );
            $this->finishMutation($mutationContext, $ownsMutationContext);
            return true;
        });

        return $updated
            ? Result::success('게시판이 수정되었습니다.')
            : Result::failure('게시판 수정에 실패했습니다.');
    }

    /**
     * 게시판 삭제
     *
     * @param int $boardId 게시판 ID
     * @return Result
     */
    public function deleteBoard(int $boardId, ?SiteMutationContext $mutationContext = null): Result
    {
        $board = $this->repository->find($boardId);

        if (!$board) {
            return Result::failure('게시판을 찾을 수 없습니다.');
        }

        // 게시글이 있는지 확인
        $articleCount = $this->repository->getArticleCount($boardId);
        if ($articleCount > 0) {
            return Result::failure("게시글({$articleCount}개)이 있어 삭제할 수 없습니다.");
        }

        // 삭제 전 정보 캡처 (이벤트용)
        $domainId = $board->getDomainId();
        $boardSlug = $board->getBoardSlug();
        $boardName = $board->getBoardName();

        $ownsMutationContext = $mutationContext === null;
        $mutationContext = $this->mutationContext($domainId, $mutationContext);
        if ($mutationContext === null) {
            $affected = $this->repository->delete($boardId);
            if ($affected <= 0) {
                return Result::failure('게시판 삭제에 실패했습니다.');
            }
            // 이벤트 발행 (메뉴 자동 삭제 등)
            $this->dispatch(new BoardConfigDeletedEvent($domainId, $boardId, $boardSlug, $boardName));
            return Result::success('게시판이 삭제되었습니다.');
        }

        $affected = $this->repository->getDb()->transaction(function () use (
            $domainId,
            $boardId,
            $boardSlug,
            $boardName,
            $mutationContext,
            $ownsMutationContext
        ): int {
            $affected = $this->repository->delete($boardId);
            if ($affected <= 0) {
                return $affected;
            }
            $this->recordBoardMutation($domainId, $boardId, $mutationContext, true);
            $this->finishMutation($mutationContext, $ownsMutationContext);
            $event = new BoardConfigDeletedEvent($domainId, $boardId, $boardSlug, $boardName);
            $this->repository->getDb()->afterCommit(fn () => $this->dispatch($event));
            return $affected;
        });

        return $affected > 0
            ? Result::success('게시판이 삭제되었습니다.')
            : Result::failure('게시판 삭제에 실패했습니다.');
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param int[] $boardIds 정렬된 게시판 ID 배열
     * @return Result
     */
    public function updateOrder(
        int $domainId,
        array $boardIds,
        ?SiteMutationContext $mutationContext = null
    ): Result
    {
        if (empty($boardIds)) {
            return Result::failure('정렬할 게시판 목록이 비어있습니다.');
        }

        $boards = [];
        foreach ($boardIds as $boardId) {
            $board = $this->repository->find((int) $boardId);
            if ($board === null || $board->getDomainId() !== $domainId) {
                return Result::failure('다른 도메인이거나 존재하지 않는 게시판이 포함되어 있습니다.');
            }
            $boards[] = $board;
        }

        $ownsMutationContext = $mutationContext === null;
        $mutationContext = $this->mutationContext($domainId, $mutationContext);
        if ($mutationContext === null) {
            $result = $this->repository->updateOrder($boardIds);
        } else {
            $before = [];
            foreach ($boards as $board) {
                $before[$board->getBoardId()] = $this->boardFingerprint($domainId, $board->getBoardId());
            }
            $result = $this->repository->getDb()->transaction(function () use (
                $boardIds,
                $boards,
                $domainId,
                $before,
                $mutationContext,
                $ownsMutationContext
            ): bool {
                if (!$this->repository->updateOrder($boardIds)) {
                    return false;
                }
                foreach ($boards as $board) {
                    $boardId = $board->getBoardId();
                    $this->recordBoardMutation(
                        $domainId,
                        $boardId,
                        $mutationContext,
                        false,
                        $before[$boardId]
                    );
                }
                $this->finishMutation($mutationContext, $ownsMutationContext);
                return true;
            });
        }

        if ($result) {
            return Result::success('정렬 순서가 변경되었습니다.');
        }

        return Result::failure('정렬 순서 변경에 실패했습니다.');
    }

    /**
     * 선택 옵션 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array [['value' => id, 'label' => name], ...]
     */
    public function getSelectOptions(int $domainId): array
    {
        return $this->repository->getSelectOptions($domainId);
    }

    /**
     * 슬러그 사용 가능 여부 확인
     *
     * @param int $domainId 도메인 ID
     * @param string $slug 슬러그
     * @param int|null $excludeId 제외할 게시판 ID (수정 시)
     * @return Result
     */
    public function checkSlugAvailability(int $domainId, string $slug, ?int $excludeId = null): Result
    {
        // 슬러그 유효성 검증
        $validation = $this->validateSlug($slug);
        if ($validation->isFailure()) {
            return $validation;
        }

        // 중복 검사
        $exists = $excludeId
            ? $this->repository->existsBySlugExceptSelf($domainId, $slug, $excludeId)
            : $this->repository->existsBySlug($domainId, $slug);

        if ($exists) {
            return Result::failure('이미 사용중인 슬러그입니다.');
        }

        return Result::success('사용 가능한 슬러그입니다.');
    }

    /**
     * 일괄 업데이트
     *
     * @param array $items [boardId => [field => value, ...], ...]
     * @return Result
     */
    public function batchUpdate(array $items, ?SiteMutationContext $mutationContext = null): Result
    {
        if (empty($items)) {
            return Result::failure('수정할 항목이 없습니다.', ['updated' => 0]);
        }

        if ($mutationContext === null && $this->mutationRecorder === null) {
            $updated = 0;
            foreach ($items as $boardId => $data) {
                $boardId = (int) $boardId;
                $board = $this->repository->find($boardId);

                if ($board) {
                    $normalizedData = $this->normalizeData($data);
                    unset($normalizedData['domain_id'], $normalizedData['sort_order'], $normalizedData['board_slug']);
                    if (!empty($normalizedData)) {
                        $this->repository->update($boardId, $normalizedData);
                        $updated++;
                    }
                }
            }
            return $updated > 0
                ? Result::success("{$updated}개 항목이 수정되었습니다.", ['updated' => $updated])
                : Result::failure('수정된 항목이 없습니다.', ['updated' => 0]);
        }

        $operations = [];
        foreach ($items as $boardId => $data) {
            $boardId = (int) $boardId;
            $board = $this->repository->find($boardId);
            if ($board === null) {
                continue;
            }
            if ($mutationContext !== null && $mutationContext->domainId !== $board->getDomainId()) {
                return Result::failure('공유 변경 문맥에 다른 도메인의 게시판이 포함되어 있습니다.');
            }
            $normalizedData = $this->normalizeData($data);
            unset($normalizedData['domain_id'], $normalizedData['sort_order'], $normalizedData['board_slug']);
            if ($normalizedData !== []) {
                $operations[] = [
                    'board' => $board,
                    'data' => $normalizedData,
                    'before' => $this->boardFingerprint($board->getDomainId(), $boardId),
                ];
            }
        }
        if ($operations === []) {
            return Result::failure('수정된 항목이 없습니다.', ['updated' => 0]);
        }

        /** @var array<int, SiteMutationContext> $contexts */
        $contexts = [];
        $updated = $this->repository->getDb()->transaction(function () use (
            $operations,
            $mutationContext,
            &$contexts
        ): int {
            $updated = 0;
            foreach ($operations as $operation) {
                /** @var BoardConfig $board */
                $board = $operation['board'];
                $domainId = $board->getDomainId();
                $contexts[$domainId] ??= $mutationContext
                    ?? SiteMutationContext::create($domainId, SiteMutationContext::SOURCE_ADMIN);
                $this->repository->update($board->getBoardId(), $operation['data']);
                $this->recordBoardMutation(
                    $domainId,
                    $board->getBoardId(),
                    $contexts[$domainId],
                    false,
                    $operation['before']
                );
                $updated++;
            }
            if ($mutationContext === null) {
                foreach ($contexts as $context) {
                    $this->finishMutation($context, true);
                }
            }
            return $updated;
        });

        return Result::success("{$updated}개 항목이 수정되었습니다.", ['updated' => $updated]);
    }

    /**
     * 슬러그 유효성 검증
     *
     * @param string $slug 슬러그
     * @return Result
     */
    public function validateSlug(string $slug): Result
    {
        // 빈 문자열 검사
        if (empty($slug)) {
            return Result::failure('슬러그를 입력해주세요.');
        }

        // 길이 검사 (2~50자)
        if (strlen($slug) < 2 || strlen($slug) > 50) {
            return Result::failure('슬러그는 2~50자 사이로 입력해주세요.');
        }

        // 형식 검사 (영문 소문자, 숫자, 하이픈만 허용)
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return Result::failure('슬러그는 영문 소문자, 숫자, 하이픈(-)만 사용할 수 있습니다.');
        }

        // 시작/끝 하이픈 검사
        if (str_starts_with($slug, '-') || str_ends_with($slug, '-')) {
            return Result::failure('슬러그는 하이픈으로 시작하거나 끝날 수 없습니다.');
        }

        // 예약어 검사
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return Result::failure('예약된 슬러그는 사용할 수 없습니다.');
        }

        return Result::success();
    }

    /**
     * 반응 설정 검증
     *
     * 저장되는 각 반응은 이모지와 라벨이 모두 있어야 한다.
     * (관리자 폼의 AJAX 저장은 HTML5 required 를 강제하지 않으므로 서버에서 검증)
     *
     * @return string|null 오류 메시지, 통과 시 null
     */
    private function validateReactionConfig(array $data): ?string
    {
        if (!isset($data['reaction_config']) || !is_array($data['reaction_config'])) {
            return null;
        }

        foreach ($data['reaction_config'] as $item) {
            $icon = trim((string) ($item['icon'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            if ($icon === '' || $label === '') {
                return '반응의 이모지와 라벨을 모두 입력해주세요.';
            }
        }

        return null;
    }

    /**
     * 데이터 정규화
     *
     * FormHelper::normalizeFormData() 활용
     *
     * @param array $data 입력 데이터
     * @return array 정규화된 데이터
     */
    private function normalizeData(array $data): array
    {
        // FormHelper 스키마 정의
        $schema = [
            'numeric' => [
                'group_id',
                'list_level', 'read_level', 'write_level', 'comment_level', 'download_level',
                'notice_count', 'per_page',
                'file_count_limit', 'file_size_limit',
                'sort_order',
            ],
            'bool' => [
                'use_secret', 'is_secret_board',
                'use_category', 'use_comment', 'use_reaction', 'use_link', 'use_file',
                'use_separate_table',
                'is_active',
                'is_global',
            ],
        ];

        // 서비스 계층은 부분 데이터 호출을 허용한다.
        // "빈 체크박스 = 해제(0)" 채움은 관리자 컨트롤러(getFormSchema) 경계에서
        // 이미 끝났으므로, 여기서는 입력에 존재하는 bool 만 강제한다.
        // 없는 키까지 0으로 채우면 프로그램 호출의 부분 업데이트가 파괴적이 된다
        // (미지정 필드가 전부 꺼짐 — is_active 포함).
        $schema['bool'] = array_values(array_intersect($schema['bool'], array_keys($data)));

        $normalized = FormHelper::normalizeFormData($data, $schema);

        // 레벨별 1일 제한 (JSON): {"level_value": limit, ...}
        foreach (['daily_write_limit', 'daily_comment_limit'] as $limitField) {
            if (isset($data[$limitField]) && is_array($data[$limitField])) {
                $limits = [];
                foreach ($data[$limitField] as $levelValue => $limit) {
                    if ($limit !== '' && $limit !== null) {
                        $limits[(string) $levelValue] = (int) $limit;
                    }
                }
                $normalized[$limitField] = !empty($limits)
                    ? json_encode($limits, JSON_UNESCAPED_UNICODE)
                    : null;
            } elseif (array_key_exists($limitField, $data)) {
                $normalized[$limitField] = null;
            }
        }

        // 문자열 필드 (빈 문자열은 null로 처리)
        $stringFields = ['board_slug', 'board_name', 'board_description', 'board_skin', 'board_editor', 'file_extension_allowed', 'table_name'];
        foreach ($stringFields as $field) {
            if (isset($data[$field])) {
                $value = trim($data[$field]);
                $normalized[$field] = ($value === '') ? null : $value;
            }
        }

        // 도메인 특화 후처리: 슬러그는 소문자로 변환
        if (isset($normalized['board_slug'])) {
            $normalized['board_slug'] = strtolower($normalized['board_slug']);
        }

        // 도메인 특화 JSON 필드: board_admin_ids
        if (isset($data['board_admin_ids'])) {
            if (is_array($data['board_admin_ids'])) {
                $normalized['board_admin_ids'] = json_encode(array_map('intval', $data['board_admin_ids']), JSON_UNESCAPED_UNICODE);
            } elseif (is_string($data['board_admin_ids']) && !empty($data['board_admin_ids'])) {
                $ids = array_map('intval', explode(',', $data['board_admin_ids']));
                $normalized['board_admin_ids'] = json_encode(array_filter($ids), JSON_UNESCAPED_UNICODE);
            } else {
                $normalized['board_admin_ids'] = null;
            }
        }

        // 도메인 특화 JSON 필드: reaction_config (복잡한 변환 로직)
        if (isset($data['reaction_config'])) {
            if (is_array($data['reaction_config'])) {
                $reactionConfig = [];
                foreach ($data['reaction_config'] as $item) {
                    $key = !empty($item['key'])
                        ? strtolower(preg_replace('/[^a-z0-9_]/', '', $item['key']))
                        : '';
                    if (empty($key)) {
                        $key = 'r_' . bin2hex(random_bytes(4));
                    }
                    $reactionConfig[$key] = [
                        'label' => trim($item['label'] ?? ''),
                        'icon' => trim($item['icon'] ?? ''),
                        'color' => $item['color'] ?? '#3B82F6',
                        'enabled' => !empty($item['enabled']),
                    ];
                }
                $normalized['reaction_config'] = !empty($reactionConfig)
                    ? json_encode($reactionConfig, JSON_UNESCAPED_UNICODE)
                    : null;
            } elseif (is_string($data['reaction_config']) && !empty($data['reaction_config'])) {
                $normalized['reaction_config'] = $data['reaction_config'];
            } else {
                $normalized['reaction_config'] = null;
            }
        }

        // 도메인 특화 후처리: 파일 크기 변환 (MB → bytes)
        if (isset($data['file_size_limit_mb'])) {
            $normalized['file_size_limit'] = (int) ($data['file_size_limit_mb'] * 1048576);
        }

        // 비-DB 필드 제거 (별도 처리되는 필드)
        unset($normalized['category_ids'], $normalized['file_size_limit_mb']);

        return $normalized;
    }

    /**
     * 사용 가능한 스킨 목록 조회
     */
    public function getAvailableSkins(): array
    {
        return DirectoryHelper::getSelectOptions('packages/Board/views/Front/Board', 'basic');
    }

    /**
     * 사용 가능한 에디터 목록 조회 (디렉토리 스캔)
     *
     * 코어 기본설정 화면(SettingsController::getEditorOptions)과 같은 방식으로 만든다
     * — 설치된 에디터 디렉토리를 그대로 옵션으로 쓰고, 런타임 기본값(mublo-editor)을
     * 최상단에, textarea 는 마지막 폴백으로 둔다.
     */
    public function getAvailableEditors(): array
    {
        $editors = DirectoryHelper::getSubdirectories('public/assets/lib/editor');

        $options = [];
        if (in_array('mublo-editor', $editors, true)) {
            $options['mublo-editor'] = 'mublo-editor';
        }
        foreach ($editors as $editor) {
            if ($editor !== 'mublo-editor') {
                $options[$editor] = $editor;
            }
        }

        // textarea는 항상 포함 (HTML 미지원 폴백)
        $options['textarea'] = '기본 텍스트 (HTML 미지원)';

        return $options;
    }

}
