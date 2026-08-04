<?php
declare(strict_types=1);
/**
 * src/Controller/Admin/DomainsController.php
 *
 * 관리자 도메인 관리 컨트롤러
 *
 * URL: /admin/domains
 */

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Domain\DomainService;
use Mublo\Service\Domain\DomainVerificationService;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Auth\ProxyLoginService;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Domain\DomainSettingsLinksEvent;
use Mublo\Helper\Form\FormHelper;

class DomainsController
{
    private DomainService $domainService;
    private AuthService $authService;
    private ProxyLoginService $proxyLoginService;
    private ?EventDispatcher $eventDispatcher;
    private ?\Mublo\Service\Extension\ExtensionService $extensionService;
    private ?DomainVerificationService $verificationService;

    public function __construct(
        DomainService $domainService,
        AuthService $authService,
        ProxyLoginService $proxyLoginService,
        ?EventDispatcher $eventDispatcher = null,
        ?\Mublo\Service\Extension\ExtensionService $extensionService = null,
        ?DomainVerificationService $verificationService = null
    ) {
        $this->domainService = $domainService;
        $this->authService = $authService;
        $this->proxyLoginService = $proxyLoginService;
        $this->eventDispatcher = $eventDispatcher;
        $this->extensionService = $extensionService;
        $this->verificationService = $verificationService;
    }

    // =========================================================================
    // 목록
    // =========================================================================

    /**
     * 도메인 목록
     *
     * 자기 도메인 + 하위 도메인 전체를 표시
     *
     * GET /admin/domains
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $currentDomainId = $context->getDomainId();

        // 페이징/검색 파라미터
        $page = (int) ($request->get('page') ?? 1);
        $defaultPerPage = (int) ($context->getDomainInfo()?->getSiteConfig()['per_page'] ?? 20);
        $perPage = (int) ($request->get('per_page') ?? $defaultPerPage);
        // 초기 로드 시 field는 빈값('검색 필드' placeholder). 미선택 검색은 전체 필드 OR로 처리됨.
        $searchField = $request->get('search_field') ?? '';
        $searchKeyword = $request->get('search_keyword') ?? '';

        // 필터 파라미터
        $statusFilter = $request->get('status') ?? '';

        // 검색 조건
        $search = [];
        if ($searchKeyword) {
            $search = [
                'field' => $searchField,
                'keyword' => $searchKeyword,
            ];
        }

        // 필터 조건 — 자기 도메인 + 하위 도메인 전체(모든 후손)
        //
        // 자기 도메인도 목록의 한 행이다(관리자 등급 무관 — 자기 사이트 주소는 볼 수 있어야
        // 한다). 호스트명 변경 권한만 최고관리자로 제한된다. 정렬·페이징은 건드리지 않는다.
        $isSuper = $this->authService->isSuper();
        $filters = [];
        if ($currentDomainId) {
            $currentDomainGroup = $context->getDomainGroup() ?? '';

            if ($currentDomainGroup !== '') {
                $filters['self_or_child_of_domain_group'] = $currentDomainGroup;
            } else {
                // 그룹 정보가 없으면 계층 판정이 불가능하다 — 자기 도메인만 보여준다.
                $filters['domain_id'] = $currentDomainId;
            }
        }
        if ($statusFilter) {
            $filters['status'] = $statusFilter;
        }

        $result = $this->domainService->getList($page, $perPage, $search, $filters);
        $rowDomains = $result['data'];

        // 패키지별 설정 링크 수집 (이벤트로 각 패키지가 등록)
        $settingsLinks = [];
        if ($this->eventDispatcher) {
            $event = new DomainSettingsLinksEvent();
            $this->eventDispatcher->dispatch($event);
            $settingsLinks = $event->getLinks();
        }

        // 소유자 체인 표기용: 그룹 경로(1/4/5)의 각 단계 도메인 소유자 아이디 맵
        // (도메인 회원은 회원 관리 목록에서 빠지므로 여기서 아이디를 노출)
        $pathDomainIds = [];
        foreach ($rowDomains as $domain) {
            foreach (explode('/', (string) $domain->getDomainGroup()) as $seg) {
                if ($seg !== '') {
                    $pathDomainIds[] = (int) $seg;
                }
            }
        }
        $domainOwnerMap = $this->domainService->getOwnerUserIdByDomainIds($pathDomainIds);

        // 도메인별 설치 패키지 맵 (설정 링크 필터링용)
        $domainPackagesMap = [];
        if ($this->extensionService && !empty($settingsLinks)) {
            foreach ($rowDomains as $domain) {
                $domainId = $domain->getDomainId();
                $domainPackagesMap[$domainId] = $this->extensionService->getEnabledPackages($domainId);
            }
        }

        // 목록 상태(검색/필터/페이지) 쿼리스트링 — 수정 후 목록 복귀 시 유지용
        // activeCode도 포함해야 복귀 시 좌측 메뉴 활성화가 유지됨(AdminViewRenderer는 ?activeCode 우선)
        $listQuery = http_build_query(array_filter([
            'activeCode'     => $request->get('activeCode') ?: '002_003',
            'search_field'   => $searchKeyword ? $searchField : '',
            'search_keyword' => $searchKeyword,
            'status'         => $statusFilter,
            'page'           => $page > 1 ? $page : '',
        ], fn($v) => $v !== '' && $v !== null));

        return ViewResponse::view('domains/index')
            ->withData([
                'pageTitle' => '도메인 관리',
                'listQuery' => $listQuery,
                'domains' => $rowDomains,
                // 지금 접속 중인 도메인 (그 행은 일괄 수정·삭제 대상이 아니다)
                'currentDomainId' => $currentDomainId ?? 0,
                'isSuper' => $isSuper,
                'pagination' => [
                    'totalItems' => $result['totalItems'],
                    'currentPage' => $result['currentPage'],
                    'perPage' => $result['perPage'],
                    'totalPages' => $result['totalPages'],
                ],
                'searchFields' => $this->getSearchFields(),
                'statusOptions' => $this->domainService->getStatusOptions(),
                'settingsLinks' => $settingsLinks,
                'domainPackagesMap' => $domainPackagesMap,
                'domainOwnerMap' => $domainOwnerMap,
                // 현재 검색/필터 값
                'currentSearch' => [
                    'field' => $searchField,
                    'keyword' => $searchKeyword,
                ],
                'currentFilters' => [
                    'status' => $statusFilter,
                ],
                'activeCode' => '002_003',
            ]);
    }

    // =========================================================================
    // 생성
    // =========================================================================

    /**
     * 도메인 생성 폼
     *
     * 상위 관리자가 사이트 생성 시 기본 정보만 입력
     *
     * GET /admin/domains/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        // 사이트 생성 권한 체크 (슈퍼관리자 또는 can_create_site 개인 권한)
        if (!$this->canCreateSite()) {
            return ViewResponse::view('Error/403')
                ->withData(['message' => '사이트 생성 권한이 없습니다.']);
        }

        // 플러그인 확장 섹션 (CloudflareDns 등) — 등록 시에는 domainId=0
        $formExtras = [];
        if ($this->eventDispatcher) {
            $formEvent = $this->eventDispatcher->dispatch(
                new \Mublo\Core\Event\Domain\DomainFormRenderingEvent(0)
            );
            $formExtras = $formEvent->getSections();
        }

        return ViewResponse::view('domains/form')
            ->withData([
                'pageTitle' => '도메인 등록',
                'isEdit' => false,
                'listUrl' => '/admin/domains?activeCode=002_003', // 등록 취소 시 목록 복귀(activeCode 유지)
                'domain' => null,
                'statusOptions' => $this->domainService->getStatusOptions(),
                'formExtras' => $formExtras,
                'activeCode' => '002_003',
            ]);
    }

    /**
     * 도메인 생성 처리
     *
     * domain_group은 자동 생성됨: {현재 도메인의 domain_group}/{새 도메인 ID}
     *
     * POST /admin/domains/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        // 사이트 생성 권한 체크 (서버 재검증)
        if (!$this->canCreateSite()) {
            return JsonResponse::error('사이트 생성 권한이 없습니다.');
        }

        $request = $context->getRequest();

        // formData[필드명] 형식으로 전송된 데이터 정제
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 현재 도메인의 domain_group 조회 (하위 도메인 생성 시 부모 그룹으로 사용)
        $currentDomainId = $context->getDomainId();
        $parentDomainGroup = '';

        if ($currentDomainId) {
            $currentDomain = $this->domainService->findById($currentDomainId);
            if ($currentDomain) {
                $parentDomainGroup = $currentDomain->getDomainGroup() ?? '';
            }
        }

        // 생성 작업자 = 현재 로그인한 회원 (소유자와 다를 수 있음)
        $createdBy = $this->authService->id();

        $result = $this->domainService->create($data, $parentDomainGroup, $createdBy, $currentDomainId);

        if ($result->isSuccess()) {
            // 기본 데이터 시딩은 DomainCreatedEvent → DomainEventSubscriber에서 처리

            $responseData = $result->getData();
            $responseData['redirect'] = '/admin/domains/edit/' . ($responseData['domain_id'] ?? '') . '?activeCode=002_003';
            return JsonResponse::success($responseData, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 수정
    // =========================================================================

    /**
     * 도메인 수정 폼
     *
     * 상위 관리자가 하위 도메인의 상태·자원 제한만 수정 가능
     * 기본 정보(소유자, 도메인명, 그룹)는 읽기 전용으로 표시
     *
     * GET /admin/domains/edit/{id}
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = (int) ($params[0] ?? 0);

        if ($domainId === 0) {
            // 쿼리스트링에서 id 확인
            $domainId = (int) ($context->getRequest()->get('id') ?? 0);
        }

        // 도메인 계층 검증 (ViewResponse 컨텍스트이므로 직접 검증)
        $targetDomain = $this->domainService->findById($domainId);
        if (!$targetDomain) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '도메인을 찾을 수 없습니다.']);
        }

        // 자기 도메인은 등급 무관 열람 가능하다(자기 사이트 주소·정보). 실제 호스트명 변경은
        // 최고관리자만 가능하며($canChangeDomain), 상태 등 다른 필드는 self에서 아예 잠긴다.
        // 하위 도메인 판정은 domain_group 접두사 매칭으로 한다.
        // 현재/대상 그룹 중 하나라도 비어 있으면 계층 판정이 불가능하므로 fail-closed로 거부한다.
        // (최상위 도메인·슈퍼관리자는 domain_group='1'로 비어있지 않으므로 정상 흐름은 유지된다.
        //  과거: 빈 그룹이면 검증을 건너뛰어 임의 도메인 접근이 가능하던 권한상승 취약점 수정)
        $isSelf = $domainId === $context->getDomainId();
        $isSuper = $this->authService->isSuper();
        $currentDomainGroup = $context->getDomainGroup() ?? '';
        $targetDomainGroup = $targetDomain->getDomainGroup() ?? '';

        if (!$isSelf
            && ($currentDomainGroup === '' || $targetDomainGroup === ''
                || !str_starts_with($targetDomainGroup, $currentDomainGroup . '/'))) {
            return ViewResponse::view('Error/403')
                ->withData(['message' => '해당 도메인에 대한 권한이 없습니다.']);
        }

        $domain = $targetDomain;

        // 소유자 회원 정보 조회 (Service를 통해)
        $ownerMember = null;
        $memberId = $domain->getMemberId();

        if ($memberId) {
            $ownerMember = $this->domainService->getOwnerMember($memberId);
        }

        // 플러그인 확장 섹션 (CloudflareDns 등)
        $formExtras = [];
        if ($this->eventDispatcher) {
            $formEvent = $this->eventDispatcher->dispatch(
                new \Mublo\Core\Event\Domain\DomainFormRenderingEvent($domainId, $domain->toArray())
            );
            $formExtras = $formEvent->getSections();
        }

        // 목록 복귀 URL (open-redirect 방지: /admin/domains 한정)
        $return = (string) ($context->getRequest()->get('return') ?? '');
        $listUrl = ($return === '/admin/domains' || str_starts_with($return, '/admin/domains?'))
            ? $return
            : '/admin/domains';

        return ViewResponse::view('domains/form')
            ->withData([
                'pageTitle' => $isSelf ? '현재 도메인' : '도메인 수정',
                'isEdit' => true,
                'listUrl' => $listUrl,
                'domain' => $domain,
                'ownerMember' => $ownerMember,
                'statusOptions' => $this->domainService->getStatusOptions(),
                'formExtras' => $formExtras,
                // 호스트명 변경 표면 — 최고관리자에게만 연다
                'isSelf' => $isSelf,
                'canChangeDomain' => $isSuper,
                'changeHistory' => $isSuper ? $this->getDomainChangeHistory($domainId) : [],
                'activeCode' => '002_003',
            ]);
    }

    /**
     * 도메인 수정 처리
     *
     * 상위 관리자는 상태만 수정 가능
     * 기본 정보(domain, member_id, domain_group)는 변경 불가
     *
     * POST /admin/domains/update/{id}
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $domainId = (int) ($params[0] ?? 0);

        // 도메인 계층 검증
        $hierarchyError = $this->verifyDomainHierarchy($domainId, $context);
        if ($hierarchyError !== null) {
            return $hierarchyError;
        }

        $request = $context->getRequest();

        // formData[필드명] 형식으로 전송된 데이터 정제
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 기본 정보 필드는 수정 불가 - 명시적으로 제거
        unset($data['domain'], $data['member_id'], $data['domain_group']);

        // 수정 가능한 필드만 필터링
        $allowedFields = ['status'];
        $data = array_intersect_key($data, array_flip($allowedFields));

        if (empty($data)) {
            return JsonResponse::error('수정할 데이터가 없습니다.');
        }

        $result = $this->domainService->update($domainId, $data);

        if ($result->isSuccess()) {
            // redirect는 클라이언트가 목록 복귀 URL(listUrl, 검색·필터·페이지 유지)로 처리
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 삭제
    // =========================================================================

    /**
     * 도메인 삭제
     *
     * DELETE /admin/domains/delete/{id}
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = (int) ($params[0] ?? 0);

        // 도메인 계층 검증
        $hierarchyError = $this->verifyDomainHierarchy($domainId, $context);
        if ($hierarchyError !== null) {
            return $hierarchyError;
        }

        $result = $this->domainService->delete($domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 상태 변경
    // =========================================================================

    /**
     * 단일 도메인 상태 변경
     *
     * POST /admin/domains/status-edit/{id}
     */
    public function statusEdit(array $params, Context $context): JsonResponse
    {
        $domainId = (int) ($params[0] ?? 0);

        // 도메인 계층 검증
        $hierarchyError = $this->verifyDomainHierarchy($domainId, $context);
        if ($hierarchyError !== null) {
            return $hierarchyError;
        }

        $request = $context->getRequest();
        $status = $request->input('status') ?? $request->json('status') ?? '';

        $result = $this->domainService->changeStatus($domainId, $status);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 일괄 상태 변경
     *
     * POST /admin/domains/bulk-status-edit
     */
    public function bulkStatusEdit(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainIds = $request->input('domain_ids') ?? $request->json('domain_ids') ?? [];
        $status = $request->input('status') ?? $request->json('status') ?? '';

        if (!is_array($domainIds)) {
            $domainIds = explode(',', $domainIds);
        }
        $domainIds = array_map('intval', array_filter($domainIds));

        // 각 도메인에 대한 계층 검증
        foreach ($domainIds as $targetDomainId) {
            $hierarchyError = $this->verifyDomainHierarchy($targetDomainId, $context);
            if ($hierarchyError !== null) {
                return $hierarchyError;
            }
        }

        $result = $this->domainService->bulkChangeStatus($domainIds, $status);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 목록에서 select box로 변경된 값들을 일괄 저장
     *
     * POST /admin/domains/list-modify
     */
    public function listModify(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        // 폼에서 전송된 데이터: chk[] = [domain_id, ...], status[domain_id] = value, ...
        $checkedIds = $request->input('chk') ?? [];

        if (empty($checkedIds)) {
            return JsonResponse::error('수정할 항목을 선택해주세요.');
        }

        // 각 필드별 데이터 수집
        $statusData = $request->input('status') ?? [];

        $updated = 0;
        $failed = 0;

        foreach ($checkedIds as $domainId) {
            $domainId = (int) $domainId;

            // 기본 도메인(ID=1)은 수정 불가
            if ($domainId === 1) {
                $failed++;
                continue;
            }

            // 도메인 계층 검증
            $hierarchyError = $this->verifyDomainHierarchy($domainId, $context);
            if ($hierarchyError !== null) {
                $failed++;
                continue;
            }

            $updateData = [];

            // 상태 변경
            if (isset($statusData[$domainId])) {
                $updateData['status'] = $statusData[$domainId];
            }

            if (!empty($updateData)) {
                $result = $this->domainService->update($domainId, $updateData);
                if ($result->isSuccess()) {
                    $updated++;
                } else {
                    $failed++;
                }
            }
        }

        if ($updated > 0) {
            $message = "{$updated}개 도메인이 수정되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개 실패)";
            }
            return JsonResponse::success(['updated' => $updated, 'failed' => $failed], $message);
        }

        return JsonResponse::error('수정된 항목이 없습니다.');
    }

    /**
     * 목록 일괄 삭제
     *
     * POST /admin/domains/list-delete
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

        foreach ($chk as $domainId) {
            $domainId = (int) $domainId;

            // 기본 도메인(ID=1)은 삭제 불가
            if ($domainId === 1) {
                $failed++;
                continue;
            }

            // 도메인 계층 검증
            $hierarchyError = $this->verifyDomainHierarchy($domainId, $context);
            if ($hierarchyError !== null) {
                $failed++;
                continue;
            }

            $result = $this->domainService->delete($domainId);
            if ($result->isSuccess()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($deleted > 0) {
            $message = "{$deleted}개 도메인이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개 실패)";
            }
            return JsonResponse::success(['deleted' => $deleted, 'failed' => $failed], $message);
        }

        return JsonResponse::error('삭제할 수 있는 항목이 없습니다.');
    }

    // =========================================================================
    // AJAX: 소유자 검증
    // =========================================================================

    /**
     * 도메인 소유자 자격 검증
     *
     * 검증 항목:
     * 1. 회원 존재 여부
     * 2. can_operate_domain 권한 보유 여부
     * 3. 등록자(관리자)와 같은 도메인 그룹 소속 여부
     * 4. 확장 패키지가 제공하는 운영 정책
     *
     * POST /admin/domains/check-owner
     */
    public function checkOwner(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $userId = $request->input('user_id') ?? $request->json('user_id') ?? '';

        // 현재 관리자의 domain_group 조회
        $currentDomainId = $context->getDomainId();

        if (!$currentDomainId) {
            return JsonResponse::error('도메인 정보를 찾을 수 없습니다.');
        }

        $adminDomainGroup = '';
        $currentDomain = $this->domainService->findById($currentDomainId);
        if ($currentDomain) {
            $adminDomainGroup = $currentDomain->getDomainGroup() ?? '';
        }

        $result = $this->domainService->validateDomainOwner($currentDomainId, $userId, $adminDomainGroup);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // AJAX: 중복 확인
    // =========================================================================

    /**
     * 도메인 중복 확인
     *
     * POST /admin/domains/check-duplicate
     */
    public function checkDuplicate(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domain = $request->input('domain') ?? $request->json('domain') ?? '';
        $excludeId = $request->input('exclude_id') ?? $request->json('exclude_id') ?? null;

        if ($excludeId !== null) {
            $excludeId = (int) $excludeId;
        }

        $result = $this->domainService->checkDomainAvailability($domain, $excludeId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 대리 로그인
    // =========================================================================

    /**
     * 하위 도메인 관리자로 대리 로그인
     *
     * POST /admin/domains/proxy-login/{id}
     */
    public function proxyLogin(array $params, Context $context): JsonResponse
    {
        $targetDomainId = (int) ($params['id'] ?? $params[0] ?? 0);

        // 계층 검증
        $error = $this->verifyDomainHierarchy($targetDomainId, $context);
        if ($error) {
            return $error;
        }

        // 대상 도메인 정보
        $targetDomain = $this->domainService->findById($targetDomainId);
        if (!$targetDomain) {
            return JsonResponse::error('도메인을 찾을 수 없습니다.');
        }

        if (!$targetDomain->isActive()) {
            return JsonResponse::error('비활성 도메인에는 접속할 수 없습니다.');
        }

        // 토큰 생성
        $sourceDomainId = $context->getDomainId();
        $adminMemberId = $this->authService->id();
        $request = $context->getRequest();
        $redirectUrl = $request->input('redirect') ?? $request->json('redirect') ?? '/admin/dashboard';

        $result = $this->proxyLoginService->generateToken($sourceDomainId, $targetDomainId, $adminMemberId, $redirectUrl);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        // 대상 도메인 URL 구성
        $targetUrl = '//' . $targetDomain->getDomain() . '/admin/proxy-login?token=' . $result->get('token');

        return JsonResponse::success([
            'redirect' => $targetUrl,
        ], '대리 로그인 토큰이 생성되었습니다.');
    }

    // =========================================================================
    // 호스트명 변경 (최고관리자 전용)
    // =========================================================================

    /**
     * 도메인 DNS/도달 검증
     *
     * 변경 전 필수 단계. 결과는 DB에 기록되고, 변경 API가 그 기록을 다시 확인한다
     * (브라우저가 보내는 "통과했다"는 주장은 신뢰하지 않는다).
     *
     * POST /admin/domains/dns-check
     */
    public function dnsCheck(array $params, Context $context): JsonResponse
    {
        if (!$this->authService->isSuper()) {
            return JsonResponse::error('도메인 검증은 최고관리자만 실행할 수 있습니다.');
        }

        if (!$this->verificationService) {
            return JsonResponse::error('도메인 검증 서비스를 사용할 수 없습니다.');
        }

        $request = $context->getRequest();
        $domain = (string) ($request->input('domain') ?? $request->json('domain') ?? '');
        $domainId = (int) ($request->input('domain_id') ?? $request->json('domain_id') ?? 0);

        $error = $this->authorizeDomainChange($domainId, $context);
        if ($error !== null) {
            return $error;
        }

        // 형식·중복은 먼저 걸러낸다 (DNS 조회·프로브를 낭비하지 않도록)
        $availability = $this->domainService->checkDomainAvailability($domain, $domainId);
        if ($availability->isFailure()) {
            return JsonResponse::error($availability->getMessage());
        }

        $result = $this->verificationService->verify($domain, $domainId, $this->authService->id());

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage(), $result->getData());
    }

    /**
     * 도메인 호스트명 변경
     *
     * 자기 도메인을 바꾸면 현재 세션 쿠키가 옛 호스트에 묶여 있어 새 주소에서
     * 로그아웃되므로, 일회용 인계 토큰이 담긴 새 주소 URL을 redirect로 돌려준다.
     *
     * POST /admin/domains/domain-edit/{id}
     */
    public function domainEdit(array $params, Context $context): JsonResponse
    {
        if (!$this->authService->isSuper()) {
            return JsonResponse::error('도메인 변경은 최고관리자만 실행할 수 있습니다.');
        }

        $domainId = (int) ($params['id'] ?? $params[0] ?? 0);

        $error = $this->authorizeDomainChange($domainId, $context);
        if ($error !== null) {
            return $error;
        }

        $request = $context->getRequest();
        $newDomain = (string) ($request->input('domain') ?? $request->json('domain') ?? '');
        $confirm = (string) ($request->input('confirm') ?? $request->json('confirm') ?? '');

        // 오타 방지: 바꿀 도메인명을 한 번 더 입력받아 대조한다.
        if (strtolower(trim($confirm)) !== strtolower(trim($newDomain))) {
            return JsonResponse::error('확인 입력이 새 도메인명과 일치하지 않습니다.');
        }

        $actorMemberId = $this->authService->id();
        $result = $this->domainService->changeDomainName($domainId, $newDomain, $actorMemberId);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        $data = $result->getData();
        $data['redirect'] = $this->buildPostChangeRedirect(
            $domainId,
            (string) ($data['new_domain'] ?? ''),
            $context,
            $actorMemberId
        );

        return JsonResponse::success($data, $result->getMessage());
    }

    /**
     * 변경 후 이동할 URL
     *
     * 자기 도메인이면 새 호스트로 세션을 인계한다(옛 호스트의 세션 쿠키는
     * 새 호스트에서 쓸 수 없다). 하위 도메인이면 현재 주소가 그대로 유효하므로
     * 목록으로 돌아간다.
     */
    private function buildPostChangeRedirect(
        int $domainId,
        string $newDomain,
        Context $context,
        ?int $actorMemberId
    ): string {
        if ($domainId !== $context->getDomainId() || $newDomain === '') {
            return '/admin/domains?activeCode=002_003';
        }

        $loginUrl = '//' . $newDomain . '/admin/login';

        if (!$actorMemberId) {
            return $loginUrl;
        }

        $token = $this->proxyLoginService->generateToken(
            $domainId,
            $domainId,
            $actorMemberId,
            '/admin/domains?activeCode=002_003',
            $actorMemberId
        );

        if ($token->isFailure()) {
            // 인계 실패는 변경 자체를 되돌릴 사유가 아니다 — 로그인 화면으로 보낸다.
            return $loginUrl;
        }

        return '//' . $newDomain . '/admin/proxy-login?token=' . $token->get('token');
    }

    /**
     * 도메인 변경 이력 (표시용)
     *
     * 실제 변경으로 마감된 검증 기록만 온다 — 확인만 하고 끝난 시도는 이력이 아니다
     * (DomainVerificationRepository::findChangeHistory 참조).
     *
     * @return array<int,array{changed_at:string,from:string,to:string,actor:string,verdict:string}>
     */
    private function getDomainChangeHistory(int $domainId, int $limit = 10): array
    {
        if (!$this->verificationService || $domainId <= 0) {
            return [];
        }

        $rows = $this->verificationService->getChangeHistory($domainId, $limit);
        if (empty($rows)) {
            return [];
        }

        // 실행자 표기는 회원 아이디로 (회원이 삭제됐으면 ID를 그대로 노출)
        $actorMap = $this->domainService->getUserIdMapByMemberIds(
            array_map(fn($row) => (int) ($row['consumed_by'] ?? 0), $rows)
        );

        $history = [];
        foreach ($rows as $row) {
            $actorId = (int) ($row['consumed_by'] ?? 0);

            $history[] = [
                'changed_at' => (string) ($row['consumed_at'] ?? ''),
                'from' => (string) ($row['previous_host'] ?? ''),
                'to' => (string) ($row['host'] ?? ''),
                'actor' => $actorId > 0 ? ($actorMap[$actorId] ?? ('#' . $actorId)) : '',
                'verdict' => (string) ($row['verdict'] ?? ''),
            ];
        }

        return $history;
    }

    /**
     * 호스트명 변경 대상 권한 검증
     *
     * 허용: 자기 도메인(최고관리자) 또는 자신의 하위 도메인.
     * verifyDomainHierarchy()와 달리 self를 허용하는 것이 유일한 차이다.
     */
    private function authorizeDomainChange(int $domainId, Context $context): ?JsonResponse
    {
        if ($domainId <= 0) {
            return JsonResponse::error('도메인 ID가 필요합니다.');
        }

        if ($domainId === $context->getDomainId()) {
            // 자기 도메인 — 진입 시점에 이미 최고관리자만 통과했다.
            return $this->domainService->findById($domainId)
                ? null
                : JsonResponse::error('도메인을 찾을 수 없습니다.');
        }

        return $this->verifyDomainHierarchy($domainId, $context);
    }

    // =========================================================================
    // 도메인 계층 검증
    // =========================================================================

    /**
     * 대상 도메인이 현재 관리자의 하위 도메인인지 검증
     *
     * domain_group 계층 구조:
     * - 관리자 도메인 그룹이 "1" 이면, 대상은 "1/3", "1/3/5" 등이어야 함
     * - 자기 자신(같은 domain_group)은 허용하지 않음 (자신의 도메인은 다른 곳에서 관리)
     *
     * @param int $targetDomainId 대상 도메인 ID
     * @param Context $context 현재 컨텍스트
     * @return JsonResponse|null 검증 실패 시 에러 응답, 성공 시 null
     */
    private function verifyDomainHierarchy(int $targetDomainId, Context $context): ?JsonResponse
    {
        if ($targetDomainId <= 0) {
            return JsonResponse::error('도메인 ID가 필요합니다.');
        }

        $currentDomainId = $context->getDomainId();

        // 자기 자신의 도메인은 이 컨트롤러에서 수정 불가 (기본 설정에서 관리)
        if ($targetDomainId === $currentDomainId) {
            return JsonResponse::error('자신의 도메인은 이 메뉴에서 수정할 수 없습니다.');
        }

        // 대상 도메인 조회
        $targetDomain = $this->domainService->findById($targetDomainId);
        if (!$targetDomain) {
            return JsonResponse::error('도메인을 찾을 수 없습니다.');
        }

        // 현재 관리자의 domain_group 조회
        $currentDomainGroup = $context->getDomainGroup() ?? '';
        $targetDomainGroup = $targetDomain->getDomainGroup() ?? '';

        // domain_group 계층 검증: 대상 도메인이 현재 도메인의 하위여야 함.
        // 현재/대상 그룹 중 하나라도 비어 있으면 계층 판정이 불가능하므로 fail-closed로 거부한다.
        // (최상위 도메인·슈퍼관리자는 domain_group='1'로 비어있지 않으므로 정상 대리로그인은 유지된다.
        //  과거: 현재 그룹이 비면 검증을 건너뛰어 임의 도메인 대리로그인이 가능하던 권한상승 취약점 수정)
        if ($currentDomainGroup === '' || $targetDomainGroup === ''
            || !str_starts_with($targetDomainGroup, $currentDomainGroup . '/')) {
            return JsonResponse::error('해당 도메인에 대한 권한이 없습니다.');
        }

        return null; // 검증 통과
    }

    // =========================================================================
    // Helper 메서드
    // =========================================================================

    /**
     * 현재 로그인 관리자의 사이트 생성 권한 체크
     *
     * 슈퍼관리자(is_super=1)이거나 can_create_site=1인 경우 true
     */
    private function canCreateSite(): bool
    {
        $user = $this->authService->user();
        if (!$user) {
            return false;
        }
        return (bool) ($user['is_super'] ?? false)
            || (bool) ($user['can_create_site'] ?? false);
    }

    /**
     * 검색 필드 목록
     */
    private function getSearchFields(): array
    {
        return [
            'domain' => '도메인명',
            'domain_group' => '도메인 그룹',
            'site_title' => '사이트명',
        ];
    }

    /**
     * 도메인 폼 데이터 스키마
     *
     * FormHelper::normalizeFormData()에서 사용
     * 상위 관리자는 하위 도메인의 기본 정보만 수정 가능
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['member_id'],
            'required_string' => ['domain'],
            'enum' => [
                'status' => [
                    'values' => ['active', 'inactive', 'blocked'],
                    'default' => 'active',
                ],
            ],
        ];
    }
}
