<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardGroupService;
use Mublo\Packages\Board\Service\BoardCategoryService;
use Mublo\Packages\Board\Service\BoardReactionService;
use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin BoardConfigController
 *
 * 게시판 설정 관리 컨트롤러
 *
 * 자동 라우팅:
 * - GET  /admin/board/config              → index
 * - GET  /admin/board/config/create       → create
 * - GET  /admin/board/config/edit         → edit (쿼리: ?id=123)
 * - POST /admin/board/config/store        → store
 * - POST /admin/board/config/delete       → delete
 * - POST /admin/board/config/order-update → orderUpdate
 */
class BoardConfigController
{
    private BoardConfigService $boardService;
    private BoardGroupService $groupService;
    private BoardCategoryService $categoryService;
    private BoardReactionService $reactionService;
    private MemberLevelCatalogInterface $levelService;
    private MigrationRunner $migrationRunner;
    private AuthContextInterface $authService;

    private const PACKAGE_NAME = 'Board';

    public function __construct(
        BoardConfigService $boardService,
        BoardGroupService $groupService,
        BoardCategoryService $categoryService,
        BoardReactionService $reactionService,
        MemberLevelCatalogInterface $levelService,
        MigrationRunner $migrationRunner,
        AuthContextInterface $authService
    ) {
        $this->boardService = $boardService;
        $this->groupService = $groupService;
        $this->categoryService = $categoryService;
        $this->reactionService = $reactionService;
        $this->levelService = $levelService;
        $this->migrationRunner = $migrationRunner;
        $this->authService = $authService;
    }

    /**
     * 게시판 목록
     *
     * GET /admin/board/config
     */
    public function index(array $params, Context $context): ViewResponse
    {
        // 마이그레이션 체크
        $migrationCheck = $this->checkMigration();
        if ($migrationCheck) {
            return $migrationCheck;
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $result = $this->boardService->getBoardsWithArticleCount($domainId, $page, $perPage);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Config/Index')
            ->withData([
                'pageTitle' => '게시판 관리',
                'boards' => $result['items'],
                'pagination' => $result['pagination'],
                'groups' => $this->groupService->getSelectOptions($domainId),
                'levelOptions' => $this->getLevelOptions(),
            ]);
    }

    /**
     * 게시판 생성 폼
     *
     * GET /admin/board/config/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $defaultGroupId = (int) $request->query('group_id', 0);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Config/Form')
            ->withData([
                'pageTitle' => '게시판 추가',
                'isEdit' => false,
                'board' => null,
                'defaultGroupId' => $defaultGroupId,
                'groups' => $this->groupService->getSelectOptions($domainId),
                'allCategories' => $this->categoryService->getActiveCategories($domainId),
                'selectedCategoryIds' => [],
                'skins' => $this->boardService->getAvailableSkins(),
                'editors' => $this->boardService->getAvailableEditors(),
                'levelOptions' => $this->getLevelOptions(),
                'isSuper' => $this->authService->isSuper(),
            ]);
    }

    /**
     * 게시판 수정 폼
     *
     * GET /admin/board/config/edit?id=123
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $boardId = (int) $request->query('id', 0);

        if ($boardId === 0 && isset($params[0])) {
            $boardId = (int) $params[0];
        }

        $board = $this->boardService->getBoard($boardId);

        if (!$board) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '게시판을 찾을 수 없습니다.']);
        }

        // 현재 게시판에 연결된 카테고리 ID 목록
        $selectedCategoryIds = $this->categoryService->getCategoriesByBoard($boardId);
        $selectedCategoryIds = array_map(fn($cat) => $cat['category_id'], $selectedCategoryIds);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Config/Form')
            ->withData([
                'pageTitle' => '게시판 수정',
                'isEdit' => true,
                'board' => $board->toArray(),
                'articleCount' => $this->boardService->getArticleCount($boardId),
                'groups' => $this->groupService->getSelectOptions($domainId),
                'allCategories' => $this->categoryService->getActiveCategories($domainId),
                'selectedCategoryIds' => $selectedCategoryIds,
                'skins' => $this->boardService->getAvailableSkins(),
                'editors' => $this->boardService->getAvailableEditors(),
                'levelOptions' => $this->getLevelOptions(),
                'isSuper' => $this->authService->isSuper(),
                'reactionCounts' => $this->reactionService->getStatsByBoard($boardId)['by_type'] ?? [],
            ]);
    }

    /**
     * 게시판 저장 (생성/수정)
     *
     * POST /admin/board/config/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $boardId = (int) ($data['board_id'] ?? 0);

        if ($boardId > 0) {
            // 반응 설정에서 제거된 key 감지 (저장 전 기존 설정과 비교)
            $removedReactionKeys = $this->detectRemovedReactionKeys($boardId, $data);

            // 수정
            $result = $this->boardService->updateBoard($boardId, $data);

            // 저장 성공 시 제거된 반응의 공감 데이터 영구 삭제 (cascade)
            if ($result->isSuccess()) {
                foreach ($removedReactionKeys as $key) {
                    $this->reactionService->purgeBoardReactionType($boardId, $key);
                }
            }
        } else {
            // 생성
            $result = $this->boardService->createBoard($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/board/config'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 게시판 삭제
     *
     * POST /admin/board/config/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $boardId = (int) $request->json('board_id', 0);

        if ($boardId <= 0) {
            return JsonResponse::error('게시판 ID가 필요합니다.');
        }

        $result = $this->boardService->deleteBoard($boardId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 정렬 순서 변경
     *
     * POST /admin/board/config/order-update
     */
    public function orderUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $boardIds = $request->json('board_ids', []);

        if (empty($boardIds) || !is_array($boardIds)) {
            return JsonResponse::error('정렬할 게시판 목록이 필요합니다.');
        }

        $result = $this->boardService->updateOrder($domainId, $boardIds);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 목록 일괄 수정
     *
     * POST /admin/board/config/list-update
     */
    public function listUpdate(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $chk = $request->input('chk') ?? [];
        $isActiveList = $request->input('is_active') ?? [];
        $groupIdList = $request->input('group_id') ?? [];
        $listLevelList = $request->input('list_level') ?? [];
        $readLevelList = $request->input('read_level') ?? [];
        $writeLevelList = $request->input('write_level') ?? [];
        $commentLevelList = $request->input('comment_level') ?? [];
        $downloadLevelList = $request->input('download_level') ?? [];

        if (empty($chk)) {
            return JsonResponse::error('수정할 항목을 선택해주세요.');
        }

        // chk에 있는 항목만 데이터 수집
        $items = [];
        $editableFields = [
            'is_active' => $isActiveList,
            'group_id' => $groupIdList,
            'list_level' => $listLevelList,
            'read_level' => $readLevelList,
            'write_level' => $writeLevelList,
            'comment_level' => $commentLevelList,
            'download_level' => $downloadLevelList,
        ];

        foreach ($chk as $boardId) {
            $boardId = (int) $boardId;
            $data = [];
            foreach ($editableFields as $field => $list) {
                if (isset($list[$boardId])) {
                    $data[$field] = $list[$boardId];
                }
            }
            if (!empty($data)) {
                $items[$boardId] = $data;
            }
        }

        $result = $this->boardService->batchUpdate($items);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['updated' => $result->get('updated')],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 목록 일괄 삭제
     *
     * POST /admin/board/config/list-delete
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $chk = $request->input('chk') ?? [];

        if (empty($chk)) {
            return JsonResponse::error('삭제할 항목을 선택해주세요.');
        }

        $deleted = 0;
        $failed = 0;

        foreach ($chk as $boardId) {
            $boardId = (int) $boardId;
            $result = $this->boardService->deleteBoard($boardId);
            if ($result->isSuccess()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($deleted > 0) {
            $message = "{$deleted}개 항목이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개는 게시글이 있어 삭제 불가)";
            }
            return JsonResponse::success(['deleted' => $deleted], $message);
        }

        return JsonResponse::error('삭제할 수 있는 항목이 없습니다. (게시글이 있는 게시판은 삭제 불가)');
    }

    /**
     * 슬러그 중복 확인
     *
     * POST /admin/board/config/check-slug
     */
    public function checkSlug(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $slug = $request->json('slug', '');
        $excludeId = (int) $request->json('exclude_id', 0);

        $result = $this->boardService->checkSlugAvailability(
            $domainId,
            $slug,
            $excludeId > 0 ? $excludeId : null
        );

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 마이그레이션 실행
     *
     * POST /admin/board/install
     */
    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('package', self::PACKAGE_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/board/config'],
                '게시판 패키지가 설치되었습니다. (실행: ' . count($result['executed']) . '개)'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    /**
     * 반응 설정에서 제거된 key 목록 추출
     *
     * 기존 게시판의 reaction_config key 중 제출 데이터에 없는 것 = 삭제된 반응.
     * (key 정규화는 BoardConfigService::normalizeData 와 동일 규칙)
     *
     * @return string[] 제거된 반응 key 배열
     */
    private function detectRemovedReactionKeys(int $boardId, array $data): array
    {
        $board = $this->boardService->getBoard($boardId);
        if (!$board) {
            return [];
        }

        $oldKeys = array_keys($board->getReactionConfig() ?? []);
        if (empty($oldKeys)) {
            return [];
        }

        $submittedKeys = [];
        foreach (($data['reaction_config'] ?? []) as $item) {
            if (!empty($item['key'])) {
                $submittedKeys[] = strtolower(preg_replace('/[^a-z0-9_]/', '', $item['key']));
            }
        }

        return array_values(array_diff($oldKeys, $submittedKeys));
    }

    /**
     * 마이그레이션 체크 — 미실행 마이그레이션이 있으면 설치 안내 ViewResponse 반환
     */
    private function checkMigration(): ?ViewResponse
    {
        $status = $this->migrationRunner->getStatus('package', self::PACKAGE_NAME, $this->getMigrationPath());

        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Install')
                ->withData([
                    'pageTitle' => '게시판 패키지 설치',
                    'pending' => $status['pending'],
                ]);
        }

        return null;
    }

    /**
     * 마이그레이션 경로
     */
    private function getMigrationPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    private function getLevelOptions(): array
    {
        $options = [0 => '비회원'];
        foreach ($this->levelService->all() as $level) {
            $options[$level->levelValue] = $level->name;
        }
        ksort($options);

        return $options;
    }

    /**
     * 폼 데이터 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => [
                'board_id',
                'group_id',
                'list_level', 'read_level', 'write_level', 'comment_level', 'download_level',
                'notice_count', 'per_page',
                'file_count_limit', 'file_size_limit_mb',
                'sort_order',
            ],
            'bool' => [
                'use_secret',
                'use_category', 'use_comment', 'use_reaction', 'use_link', 'use_file',
                'use_separate_table',
                'is_active',
                'is_global',
            ],
            'required_string' => ['board_slug', 'board_name'],
        ];
    }
}
