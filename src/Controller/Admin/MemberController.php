<?php
/**
 * src/Controller/Admin/MemberController.php
 *
 * 관리자 회원 관리 컨트롤러
 *
 * URL: /admin/member
 */

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Member\MemberFormRenderingEvent;
use Mublo\Core\Event\Member\MemberDataEnrichingEvent;
use Mublo\Service\Member\MemberService;
use Mublo\Service\Member\MemberAdminService;
use Mublo\Service\Member\MemberFieldService;
use Mublo\Service\Member\MemberLevelService;
use Mublo\Service\Auth\AuthService;
use Mublo\Helper\Form\FormHelper;
use Mublo\Service\Balance\BalanceManager;

class MemberController
{
    private MemberService $memberService;
    private MemberAdminService $memberAdminService;
    private MemberFieldService $fieldService;
    private MemberLevelService $levelService;
    private AuthService $authService;
    private BalanceManager $balanceManager;
    private \Mublo\Service\Domain\DomainService $domainService;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(
        MemberService $memberService,
        MemberAdminService $memberAdminService,
        MemberFieldService $fieldService,
        MemberLevelService $levelService,
        AuthService $authService,
        BalanceManager $balanceManager,
        \Mublo\Service\Domain\DomainService $domainService,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->memberService = $memberService;
        $this->memberAdminService = $memberAdminService;
        $this->fieldService = $fieldService;
        $this->levelService = $levelService;
        $this->authService = $authService;
        $this->balanceManager = $balanceManager;
        $this->domainService = $domainService;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 회원 목록
     *
     * GET /admin/member
     *
     * View에서 $this->columns(), $this->listRenderHelper를 사용하여 렌더링
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 회원 관리 범위: 기본은 자기 사이트 회원만(domain_id 정확일치 = 수정권한·대시보드 카드와 동일).
        // 슈퍼관리자만 domain_group 계층으로 전체 회원을 조회한다.
        // 하위 사이트로 독립한 회원은 도메인 관리에서 도메인 단위로 관리한다.
        $currentAdmin = $this->authService->user();
        $adminIsSuper = (bool) ($currentAdmin['is_super'] ?? false);
        $domainGroup = $adminIsSuper ? $context->getDomainGroup() : null;

        // 페이징/검색 파라미터
        $page = (int) ($request->get('page') ?? 1);
        $defaultPerPage = (int) ($context->getDomainInfo()?->getSiteConfig()['per_page'] ?? 20);
        $perPage = (int) ($request->get('per_page') ?? $defaultPerPage);
        $searchField = $request->get('search_field') ?? '';
        $searchKeyword = $request->get('search_keyword') ?? '';

        // 정렬 파라미터 (allowlist 검증은 Service/Repository에서 수행)
        $sort = $request->get('sort') ?? '';
        $order = $request->get('order') ?? '';

        // 검색 필드 목록 조회 (members 컬럼 + 추가 필드)
        $searchFieldsData = $this->memberAdminService->getSearchFields($domainId);

        // 검색 조건 구성
        $search = [];
        if ($searchKeyword && $searchField) {
            $fieldData = $searchFieldsData[$searchField] ?? null;
            $search = [
                'keyword' => $searchKeyword,
                'field' => $searchField,
                'field_info' => $fieldData['field_info'] ?? null, // 추가 필드면 field_info 포함
            ];
        } elseif ($searchKeyword) {
            // 필드 미선택 → 아이디/닉네임 통합검색
            $search = ['keyword' => $searchKeyword, 'field' => ''];
        }

        // 등급/상태 필터
        $levelFilter = $request->get('level') ?? '';
        $statusFilter = $request->get('status') ?? '';
        $filters = [];
        if ($levelFilter !== '') {
            $filters['level_value'] = (int) $levelFilter;
        }
        if ($statusFilter !== '') {
            $filters['status'] = $statusFilter;
        }

        // 회원 목록 조회 (추가 필드 포함)
        // $domainGroup이 null이면 자기 사이트만(정확일치), 슈퍼관리자면 계층 전체 조회
        $result = $this->memberAdminService->getListWithFields($domainId, $page, $perPage, $search, $domainGroup, $sort, $order, $filters);
        $members = $result['data']['members'] ?? [];
        $listFields = $result['data']['listFields'] ?? [];
        $pagination = $result['data']['pagination'] ?? [];

        // 독립(사이트개설)해나간 회원의 현재 운영 도메인명 채우기 (domain_id != origin_domain_id)
        $departedDomainIds = [];
        foreach ($members as $m) {
            $origin = $m['origin_domain_id'] ?? null;
            if ($origin !== null && (int) $origin !== (int) ($m['domain_id'] ?? 0)) {
                $departedDomainIds[] = (int) $m['domain_id'];
            }
        }
        if (!empty($departedDomainIds)) {
            $domainNameMap = $this->domainService->getDomainNameMapByIds($departedDomainIds);
            foreach ($members as &$m) {
                $origin = $m['origin_domain_id'] ?? null;
                if ($origin !== null && (int) $origin !== (int) ($m['domain_id'] ?? 0)) {
                    $m['operating_domain'] = $domainNameMap[(int) $m['domain_id']] ?? '';
                }
            }
            unset($m);
        }

        // View용 검색 필드 옵션 (label 추출, 암호화 필드는 🔒 표시)
        $searchFields = [];
        foreach ($searchFieldsData as $fieldName => $data) {
            $label = $data['label'];
            $isEncrypted = $data['field_info']['is_encrypted'] ?? false;
            $searchFields[$fieldName] = $isEncrypted ? "🔒 {$label}" : $label;
        }

        // 등급 옵션 (select box용) — 자기보다 높은 등급은 노출하되 disabled 처리
        $levelOptions = $this->levelService->getOptionsForSelect();
        $disabledLevels = $this->disabledLevelValues($levelOptions, $currentAdmin);

        // 현재 정렬 상태 (헤더 표시/링크 생성용, 기본값 member_id DESC)
        [$sortField, $sortOrder] = $this->memberAdminService->normalizeSort($sort, $order);

        // 목록 상태(검색/정렬/페이지) 쿼리스트링 — 수정 후 목록 복귀 시 유지용
        // activeCode도 포함해야 복귀 시 좌측 메뉴 활성화가 유지됨(AdminViewRenderer는 ?activeCode 우선)
        $listQuery = http_build_query(array_filter([
            'activeCode'     => $request->get('activeCode') ?: '003_001',
            'search_field'   => $searchField,
            'search_keyword' => $searchKeyword,
            'level'          => $levelFilter,
            'status'         => $statusFilter,
            'sort'           => $sort,
            'order'          => $order,
            'page'           => $page > 1 ? $page : '',
        ], fn($v) => $v !== '' && $v !== null));

        // View에서 $this->columns(), $this->listRenderHelper로 렌더링
        return ViewResponse::view('member/index')
            ->withData([
                'pageTitle' => '회원 관리',
                'listQuery' => $listQuery, // 수정 링크에 붙일 복귀 쿼리
                'members' => $members,           // View에서 리스트 렌더링
                'listFields' => $listFields,     // 추가 필드 정보
                'pagination' => $pagination,
                'searchFields' => $searchFields,
                'levelOptions' => $levelOptions, // 등급 선택 옵션
                'disabledLevels' => $disabledLevels, // 비활성화할 등급값(자기보다 높은 등급)
                'selfMemberId' => (int) ($currentAdmin['member_id'] ?? 0), // 본인 행은 등급 select disabled
                'statusOptions' => $this->getStatusOptions(), // 상태 필터 옵션
                'currentSearch' => [
                    'field' => $searchField,
                    'keyword' => $searchKeyword,
                ],
                'currentFilters' => [
                    'level' => $levelFilter,
                    'status' => $statusFilter,
                ],
                'currentSort' => [
                    'field' => $sortField,
                    'order' => $sortOrder,
                ],
            ]);
    }

    /**
     * 회원 등록 폼
     *
     * GET /admin/member/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        // 추가 필드 정의 조회
        $fieldDefinitions = $this->fieldService->getFields($domainId);

        // 최고관리자 여부
        $currentAdmin = $this->authService->user();
        $adminIsSuper = (bool) ($currentAdmin['is_super'] ?? false);

        // 등급 옵션 (비회원 제외) — 자기보다 높은 등급은 노출하되 disabled 처리
        $levelOptions = $this->levelService->getOptionsForSelect(false);
        $disabledLevels = $this->disabledLevelValues($levelOptions, $currentAdmin);

        // 상태 옵션
        $statusOptions = $this->getStatusOptions();

        // 플러그인 폼 확장 이벤트
        $formEvent = new MemberFormRenderingEvent('create', null, $context);
        $this->eventDispatcher?->dispatch($formEvent);

        return ViewResponse::view('member/form')
            ->withData([
                'pageTitle' => '회원 등록',
                'mode' => 'create',
                'listUrl' => '/admin/member?activeCode=003_001', // 등록 취소 시 목록 복귀(activeCode 유지)
                'member' => null,
                'fieldDefinitions' => $fieldDefinitions,
                'fieldValues' => [],
                'levelOptions' => $levelOptions,
                'disabledLevels' => $disabledLevels,
                'statusOptions' => $statusOptions,
                'adminIsSuper' => $adminIsSuper,
                'pluginSections' => $formEvent->getSectionsSorted(),
                'pluginScripts' => $formEvent->getScriptsSorted(),
            ]);
    }

    /**
     * 회원 등록 처리
     *
     * POST /admin/member/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 현재 로그인 관리자 정보
        $currentAdmin = $this->authService->user();

        // formData[필드명] 형식으로 전송된 데이터 정제
        $formData = $request->input('formData') ?? [];
        $normalizedData = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $data = [
            'domain_id' => $domainId,
            'user_id' => trim($normalizedData['user_id'] ?? ''),
            'password' => $normalizedData['password'] ?? '',
            'nickname' => trim($normalizedData['nickname'] ?? ''),
            'level_value' => (int) ($normalizedData['level_value'] ?? 1),
            'status' => $normalizedData['status'] ?? 'active',
            'fields' => $request->input('fields') ?? [],  // 다차원 배열은 별도 처리
            // 관리자 권한 검증용
            'admin_id' => $currentAdmin['member_id'] ?? 0,
            'admin_is_super' => $currentAdmin['is_super'] ?? false,
            'admin_level_value' => (int) ($currentAdmin['level_value'] ?? 0),
            'admin_level_type' => $currentAdmin['level_type'] ?? null,
            'admin_domain_group' => $currentAdmin['domain_group'] ?? null,
        ];

        // MemberAdminService의 register 호출
        $result = $this->memberAdminService->register($data);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/member?activeCode=003_001'], // 등록 후 목록 복귀(activeCode 유지, 검색컨텍스트는 미유지)
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 회원 수정 폼
     *
     * GET /admin/member/edit/{id}
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        // autoResolve는 숫자 배열 ['123'], 명시적 라우트는 ['id' => '123']
        $memberId = (int) ($params['id'] ?? $params[0] ?? 0);
        $domainId = $context->getDomainId() ?? 1;

        // 목록 복귀 URL (open-redirect 방지: /admin/member 한정)
        $return = (string) ($context->getRequest()->get('return') ?? '');
        $listUrl = ($return === '/admin/member' || str_starts_with($return, '/admin/member?'))
            ? $return
            : '/admin/member';

        if (!$memberId) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '회원을 찾을 수 없습니다.']);
        }

        $member = $this->memberService->findById($memberId);

        $currentAdmin = $this->authService->user();
        $adminIsSuper = (bool) ($currentAdmin['is_super'] ?? false);

        // 접근 범위: 슈퍼가 아니면 현재 소속(domain_id) OR 태생(origin_domain_id) 회원만.
        // 사이트를 개설해 떠난 회원도 태생 사이트 관리자가 수정 폼에 접근할 수 있어야 한다.
        $inScope = $member
            && ($member->getDomainId() === $domainId || $member->getOriginDomainId() === $domainId);
        if (!$member || (!$adminIsSuper && !$inScope)) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '회원을 찾을 수 없습니다.']);
        }

        // 추가 필드 정의 조회
        $fieldDefinitions = $this->fieldService->getFields($domainId);

        // 추가 필드 값 조회 (field_id => value 형태로 변환)
        $fieldValuesRaw = $this->memberService->getFieldValues($memberId);
        $fieldValues = [];
        foreach ($fieldValuesRaw as $fv) {
            $fieldValues[$fv['field_id']] = $fv['field_value'];
        }

        // 등급 옵션 (비회원 제외) — 자기보다 높은 등급은 노출하되 disabled 처리
        $levelOptions = $this->levelService->getOptionsForSelect(false);
        $disabledLevels = $this->disabledLevelValues($levelOptions, $currentAdmin);

        // 본인 편집 여부 (본인 등급은 변경 불가 → 등급 select 자체를 disabled)
        $isSelf = (int) ($currentAdmin['member_id'] ?? 0) === $memberId;

        // 상태 옵션
        $statusOptions = $this->getStatusOptions();

        // 최근 포인트 내역 5건
        $recentPointLogs = array_map(
            fn($log) => $log->toArray(),
            $this->balanceManager->getRecentByMember($domainId, $memberId, 5)
        );

        $memberArray = $member->toArray();

        // 플러그인 데이터 보강 이벤트
        $enrichEvent = new MemberDataEnrichingEvent($memberId, $memberArray, 'admin_detail');
        $this->eventDispatcher?->dispatch($enrichEvent);

        // 플러그인 폼 확장 이벤트
        $formEvent = new MemberFormRenderingEvent('edit', $memberArray, $context);
        $this->eventDispatcher?->dispatch($formEvent);

        return ViewResponse::view('member/form')
            ->withData([
                'pageTitle' => '회원 정보 수정',
                'mode' => 'edit',
                'listUrl' => $listUrl, // 목록/저장 후 복귀 URL (검색·정렬·페이지 유지)
                'member' => $memberArray,
                'fieldDefinitions' => $fieldDefinitions,
                'fieldValues' => $fieldValues,
                'levelOptions' => $levelOptions,
                'disabledLevels' => $disabledLevels,
                'isSelf' => $isSelf, // 본인 편집이면 등급 select disabled
                'statusOptions' => $statusOptions,
                'adminIsSuper' => $adminIsSuper,
                'recentPointLogs' => $recentPointLogs,
                'pluginExtras' => $enrichEvent->getExtras(),
                'pluginSections' => $formEvent->getSectionsSorted(),
                'pluginScripts' => $formEvent->getScriptsSorted(),
            ]);
    }

    /**
     * 회원 정보 수정 처리
     *
     * POST /admin/member/update/{id}
     */
    public function update(array $params, Context $context): JsonResponse
    {
        // autoResolve는 숫자 배열 ['123'], 명시적 라우트는 ['id' => '123']
        $memberId = (int) ($params['id'] ?? $params[0] ?? 0);
        $request = $context->getRequest();

        if (!$memberId) {
            return JsonResponse::error('회원 ID가 필요합니다.');
        }

        // 현재 로그인 관리자 정보
        $currentAdmin = $this->authService->user();

        // formData[필드명] 형식으로 전송된 데이터 정제
        $formData = $request->input('formData') ?? [];
        $normalizedData = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $adminIsSuper = (bool) ($currentAdmin['is_super'] ?? false);

        $data = [
            'password' => $normalizedData['password'] ?? '',
            'nickname' => isset($normalizedData['nickname']) ? trim($normalizedData['nickname']) : null,
            'level_value' => $normalizedData['level_value'] ?? null,
            'status' => $normalizedData['status'] ?? null,
            'can_create_site' => $normalizedData['can_create_site'] ?? null,
            'fields' => $request->input('fields') ?? [],  // 다차원 배열은 별도 처리
            // 관리자 권한 검증용
            'admin_id' => $currentAdmin['member_id'] ?? 0,
            'admin_is_super' => $adminIsSuper,
            'admin_level_value' => (int) ($currentAdmin['level_value'] ?? 0),
            'admin_level_type' => $currentAdmin['level_type'] ?? null,
            'admin_domain_id' => $context->getDomainId() ?? 1,
        ];

        // MemberAdminService의 update 호출
        $result = $this->memberAdminService->update($memberId, $data);

        if ($result->isSuccess()) {
            // 본인 정보를 수정한 경우 세션 갱신 (아바타/닉네임 등 사이드바 즉시 반영)
            if ($memberId === (int) ($currentAdmin['member_id'] ?? 0)) {
                $this->authService->refreshSession();
            }
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 회원 삭제
     *
     * DELETE /admin/member/delete/{id}
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        // autoResolve는 숫자 배열 ['123'], 명시적 라우트는 ['id' => '123']
        $memberId = (int) ($params['id'] ?? $params[0] ?? 0);

        if (!$memberId) {
            return JsonResponse::error('회원 ID가 필요합니다.');
        }

        // 현재 로그인 관리자 정보 (도메인 경계 검증용)
        $currentAdmin = $this->authService->user();
        $adminContext = [
            'admin_domain_id' => $context->getDomainId() ?? 1,
            'admin_is_super' => $currentAdmin['is_super'] ?? false,
            'admin_id' => $currentAdmin['member_id'] ?? 0,
        ];

        $result = $this->memberAdminService->delete($memberId, $adminContext);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 중복 체크 API
     *
     * POST /admin/member/check-duplicate
     *
     * Request: { field_name: 'user_id'|'email'|..., value: string, member_id?: int }
     * Response: { result: 'success', duplicate: bool, message: string }
     */
    public function checkDuplicate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // JSON 또는 Form 데이터 모두 지원
        $fieldName = $request->input('field_name') ?? $request->json('field_name') ?? '';
        $value = $request->input('value') ?? $request->json('value') ?? '';
        $memberIdRaw = $request->input('member_id') ?? $request->json('member_id');
        $excludeMemberId = $memberIdRaw ? (int) $memberIdRaw : null;

        if (empty($fieldName) || empty($value)) {
            return JsonResponse::error('필드명과 값을 입력해주세요.');
        }

        $result = $this->memberService->checkDuplicate(
            $domainId,
            $fieldName,
            $value,
            $excludeMemberId
        );

        // Result::failure = 중복 있음, Result::success = 사용 가능
        return JsonResponse::success([
            'duplicate' => $result->isFailure(),
        ], $result->getMessage());
    }

    /**
     * 목록 일괄 수정
     *
     * POST /admin/member/listModify
     */
    public function listModify(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $checkedIds = $request->input('chk') ?? [];

        if (empty($checkedIds)) {
            return JsonResponse::error('수정할 회원을 선택해주세요.');
        }

        $currentAdmin = $this->authService->user();
        $levelValueData = $request->input('level_value') ?? [];
        $statusData = $request->input('status') ?? [];

        $updated = 0;
        $failed = 0;

        foreach ($checkedIds as $memberId) {
            $memberId = (int) $memberId;

            $data = [
                'admin_id' => $currentAdmin['member_id'] ?? 0,
                'admin_is_super' => $currentAdmin['is_super'] ?? false,
                'admin_level_value' => (int) ($currentAdmin['level_value'] ?? 0),
                'admin_level_type' => $currentAdmin['level_type'] ?? null,
                'admin_domain_id' => $context->getDomainId() ?? 1,
            ];

            if (isset($levelValueData[$memberId])) {
                $data['level_value'] = (int) $levelValueData[$memberId];
            }

            if (isset($statusData[$memberId])) {
                $data['status'] = $statusData[$memberId];
            }

            $result = $this->memberAdminService->update($memberId, $data);
            if ($result->isSuccess()) {
                $updated++;
            } else {
                $failed++;
            }
        }

        if ($updated > 0) {
            $message = "{$updated}명의 회원 정보가 수정되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}명 실패)";
            }
            return JsonResponse::success(['updated' => $updated, 'failed' => $failed], $message);
        }

        return JsonResponse::error('수정된 항목이 없습니다.');
    }

    /**
     * 목록 일괄 삭제
     *
     * POST /admin/member/listDelete
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $checkedIds = $request->input('chk') ?? [];

        if (empty($checkedIds)) {
            return JsonResponse::error('삭제할 회원을 선택해주세요.');
        }

        $currentAdmin = $this->authService->user();
        $adminContext = [
            'admin_domain_id' => $context->getDomainId() ?? 1,
            'admin_is_super' => $currentAdmin['is_super'] ?? false,
            // 단일 delete·listModify 와 동일하게 admin_id 를 실어야 도메인 주인(비-슈퍼)이
            // isDomainOwnerAdmin 판정을 통과해 하위 관리자를 벌크 삭제할 수 있다(누락 시 fail-closed).
            'admin_id' => $currentAdmin['member_id'] ?? 0,
        ];

        $deleted = 0;
        $failed = 0;

        foreach ($checkedIds as $memberId) {
            $memberId = (int) $memberId;

            $result = $this->memberAdminService->delete($memberId, $adminContext);
            if ($result->isSuccess()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($deleted > 0) {
            $message = "{$deleted}명의 회원이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}명 실패)";
            }
            return JsonResponse::success(['updated' => $deleted, 'failed' => $failed], $message);
        }

        return JsonResponse::error('삭제된 항목이 없습니다.');
    }

    /**
     * 상태 옵션 목록
     */
    /**
     * 드롭다운에서 비활성화(disabled)할 등급값 목록
     *
     * 비-슈퍼 관리자는 자기 등급보다 높은 등급을 부여할 수 없으므로(백엔드 원칙과 일치),
     * 등급 체계는 보이되 선택은 못 하도록 해당 등급 옵션을 disabled 처리한다.
     *
     * @param array $levelOptions [level_value => label]
     * @param array $currentAdmin 현재 관리자 (is_super, level_value)
     * @return array 비활성화할 level_value 목록
     */
    private function disabledLevelValues(array $levelOptions, array $currentAdmin): array
    {
        if (!empty($currentAdmin['is_super'])) {
            return [];
        }
        $adminLevel = (int) ($currentAdmin['level_value'] ?? 0);
        $disabled = [];
        foreach (array_keys($levelOptions) as $lv) {
            if ((int) $lv > $adminLevel) {
                $disabled[] = $lv;
            }
        }
        return $disabled;
    }

    private function getStatusOptions(): array
    {
        return [
            'active' => '활성',
            'inactive' => '비활성',
            'dormant' => '휴면',
            'blocked' => '차단',
        ];
    }

    /**
     * 회원 검색 API
     *
     * POST /admin/member/search
     *
     * 관리자 선택, 회원 검색 등에서 사용
     */
    public function search(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $keyword = trim($request->json('keyword', ''));
        $limit = min((int) $request->json('limit', 10), 50);

        if (strlen($keyword) < 2) {
            return JsonResponse::error('검색어는 2글자 이상 입력해주세요.');
        }

        // 아이디 또는 이름으로 검색
        $result = $this->memberAdminService->searchMembers($domainId, $keyword, $limit);

        if ($result->isSuccess()) {
            return JsonResponse::success([
                'members' => $result->get('members', []),
            ]);
        }

        return JsonResponse::error($result->getMessage() ?: '검색 중 오류가 발생했습니다.');
    }

    /**
     * 회원 폼 데이터 스키마
     *
     * FormHelper::normalizeFormData()에서 사용
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['member_id', 'level_value'],
            'bool' => ['can_create_site'],
            'required_string' => ['user_id'],
            'enum' => [
                'status' => [
                    'values' => ['active', 'inactive', 'dormant', 'blocked'],
                    'default' => 'active',
                ],
            ],
        ];
    }
}
