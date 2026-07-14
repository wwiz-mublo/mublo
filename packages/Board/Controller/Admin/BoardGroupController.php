<?php
namespace Mublo\Packages\Board\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Service\BoardGroupService;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Service\Member\MemberLevelService;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin BoardGroupController
 *
 * 게시판 그룹 관리 컨트롤러
 *
 * 자동 라우팅:
 * - GET  /admin/board/group              → index
 * - GET  /admin/board/group/create       → create
 * - GET  /admin/board/group/edit         → edit (쿼리: ?id=123)
 * - POST /admin/board/group/store        → store
 * - POST /admin/board/group/delete       → delete
 * - POST /admin/board/group/order-update → orderUpdate
 */
class BoardGroupController
{
    private BoardGroupService $groupService;
    private BoardConfigService $boardConfigService;
    private BoardPermissionService $permissionService;
    private MemberLevelService $levelService;

    public function __construct(
        BoardGroupService $groupService,
        BoardConfigService $boardConfigService,
        BoardPermissionService $permissionService,
        MemberLevelService $levelService
    ) {
        $this->groupService = $groupService;
        $this->boardConfigService = $boardConfigService;
        $this->permissionService = $permissionService;
        $this->levelService = $levelService;
    }

    /**
     * 그룹 목록
     *
     * GET /admin/board/group
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $groups = $this->groupService->getGroupsWithCount($domainId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Group/Index')
            ->withData([
                'pageTitle' => '게시판 그룹 관리',
                'groups' => $groups,
                'levelOptions' => $this->levelService->getOptionsForSelect(),
            ]);
    }

    /**
     * 그룹 생성 폼
     *
     * GET /admin/board/group/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Group/Form')
            ->withData([
                'pageTitle' => '그룹 추가',
                'isEdit' => false,
                'group' => null,
                'levelOptions' => $this->levelService->getOptionsForSelect(),
                'groupAdmins' => [],
                'boards' => [],
            ]);
    }

    /**
     * 그룹 수정 폼
     *
     * GET /admin/board/group/edit?id=123
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $groupId = (int) $request->query('id', 0);

        if ($groupId === 0 && isset($params[0])) {
            $groupId = (int) $params[0];
        }

        $group = $this->groupService->getGroup($groupId);

        if (!$group) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '그룹을 찾을 수 없습니다.']);
        }

        // 그룹 관리자 목록 (회원 정보 포함)
        $groupAdmins = $this->permissionService->getGroupAdminsWithInfo($groupId);

        // 그룹에 속한 게시판 목록
        $boardEntities = $this->boardConfigService->getBoardsByGroup($groupId);
        $boards = array_map(fn($b) => $b->toArray(), $boardEntities);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Group/Form')
            ->withData([
                'pageTitle' => '그룹 수정',
                'isEdit' => true,
                'group' => $group->toArray(),
                'boardCount' => count($boards),
                'levelOptions' => $this->levelService->getOptionsForSelect(),
                'groupAdmins' => $groupAdmins,
                'boards' => $boards,
            ]);
    }

    /**
     * 그룹 저장 (생성/수정)
     *
     * POST /admin/board/group/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $groupId = (int) ($data['group_id'] ?? 0);

        if ($groupId > 0) {
            // 수정
            $result = $this->groupService->updateGroup($groupId, $data);
        } else {
            // 생성
            $result = $this->groupService->createGroup($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/board/group'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 그룹 삭제
     *
     * POST /admin/board/group/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $groupId = (int) $request->json('group_id', 0);

        if ($groupId <= 0) {
            return JsonResponse::error('그룹 ID가 필요합니다.');
        }

        $result = $this->groupService->deleteGroup($groupId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 정렬 순서 변경
     *
     * POST /admin/board/group/order-update
     */
    public function orderUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $groupIds = $request->json('group_ids', []);

        if (empty($groupIds) || !is_array($groupIds)) {
            return JsonResponse::error('정렬할 그룹 목록이 필요합니다.');
        }

        $result = $this->groupService->updateOrder($domainId, $groupIds);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 슬러그 중복 확인
     *
     * POST /admin/board/group/check-slug
     */
    public function checkSlug(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $slug = $request->json('slug', '');
        $excludeId = (int) $request->json('exclude_id', 0);

        $result = $this->groupService->checkSlugAvailability(
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
     * 목록 일괄 수정
     *
     * POST /admin/board/group/list-update
     */
    public function listUpdate(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $chk = $request->input('chk') ?? [];
        $isActiveList = $request->input('is_active') ?? [];
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
        foreach ($chk as $groupId) {
            $groupId = (int) $groupId;
            $updateData = [];

            if (isset($isActiveList[$groupId])) {
                $updateData['is_active'] = $isActiveList[$groupId];
            }
            if (isset($listLevelList[$groupId])) {
                $updateData['list_level'] = $listLevelList[$groupId];
            }
            if (isset($readLevelList[$groupId])) {
                $updateData['read_level'] = $readLevelList[$groupId];
            }
            if (isset($writeLevelList[$groupId])) {
                $updateData['write_level'] = $writeLevelList[$groupId];
            }
            if (isset($commentLevelList[$groupId])) {
                $updateData['comment_level'] = $commentLevelList[$groupId];
            }
            if (isset($downloadLevelList[$groupId])) {
                $updateData['download_level'] = $downloadLevelList[$groupId];
            }

            if (!empty($updateData)) {
                $items[$groupId] = $updateData;
            }
        }

        $result = $this->groupService->batchUpdate($items);

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
     * POST /admin/board/group/list-delete
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

        foreach ($chk as $groupId) {
            $groupId = (int) $groupId;
            $result = $this->groupService->deleteGroup($groupId);
            if ($result->isSuccess()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($deleted > 0) {
            $message = "{$deleted}개 항목이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개는 게시판이 있어 삭제 불가)";
            }
            return JsonResponse::success(['deleted' => $deleted], $message);
        }

        return JsonResponse::error('삭제할 수 있는 항목이 없습니다. (게시판이 있는 그룹은 삭제 불가)');
    }

    /**
     * 그룹 관리자 추가
     *
     * POST /admin/board/group/admin-add
     */
    public function adminAdd(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $groupId = (int) $request->json('group_id', 0);
        $memberId = (int) $request->json('member_id', 0);

        if ($groupId <= 0 || $memberId <= 0) {
            return JsonResponse::error('그룹 ID와 회원 ID가 필요합니다.');
        }

        $result = $this->permissionService->addGroupAdmin($domainId, $groupId, $memberId);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['permission_id' => $result->get('permission_id')],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 그룹 관리자 제거
     *
     * POST /admin/board/group/admin-remove
     */
    public function adminRemove(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $groupId = (int) $request->json('group_id', 0);
        $memberId = (int) $request->json('member_id', 0);

        if ($groupId <= 0 || $memberId <= 0) {
            return JsonResponse::error('그룹 ID와 회원 ID가 필요합니다.');
        }

        $result = $this->permissionService->removeGroupAdmin($groupId, $memberId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 폼 데이터 스키마
     *
     * Note: group_admin_ids는 board_permissions 테이블로 관리
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => [
                'group_id',
                'list_level',
                'read_level',
                'write_level',
                'comment_level',
                'download_level',
                'sort_order',
            ],
            'bool' => ['is_active'],
            'required_string' => ['group_slug', 'group_name'],
        ];
    }
}
