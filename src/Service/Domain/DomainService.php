<?php

namespace Mublo\Service\Domain;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Core\Event\Domain\DomainCreatedEvent;
use Mublo\Core\Event\Domain\DomainProvisioningEvent;
use Mublo\Core\Event\Domain\DomainUpdatedEvent;
use Mublo\Core\Event\Domain\DomainDeletedEvent;
use Mublo\Core\Event\Domain\DomainOwnerValidatingEvent;
use Mublo\Entity\Domain\Domain;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Member\FieldEncryptionService;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Core\Result\Result;

/**
 * DomainService
 *
 * 도메인 Aggregate 관리 (CRUD, 상태, 검증)
 *
 * 설정(사이트/회사/SEO/테마) 관련은 DomainSettingsService로 분리됨
 *
 * @see DomainSettingsService 도메인별 설정 관리
 */
class DomainService
{
    private DomainRepository $domainRepository;
    private DomainResolver $domainResolver;
    private ?MemberRepository $memberRepository;
    private ?EventDispatcher $eventDispatcher;
    private FieldEncryptionService $encryptionService;
    private ?ExtensionService $extensionService;

    public function __construct(
        DomainRepository $domainRepository,
        DomainResolver $domainResolver,
        ?MemberRepository $memberRepository,
        ?EventDispatcher $eventDispatcher,
        FieldEncryptionService $encryptionService,
        ?ExtensionService $extensionService = null
    ) {
        $this->domainRepository = $domainRepository;
        $this->domainResolver = $domainResolver;
        $this->memberRepository = $memberRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->encryptionService = $encryptionService;
        $this->extensionService = $extensionService;
    }

    // =========================================================================
    // 이벤트 헬퍼
    // =========================================================================

    /**
     * 이벤트 발행
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    // =========================================================================
    // 응답 헬퍼
    // =========================================================================

    private function success(mixed $data = null, string $message = ''): Result
    {
        $resultData = is_array($data) ? $data : [];
        return Result::success($message, $resultData);
    }

    private function fail(string $message, ?array $errors = null): Result
    {
        return Result::failure($message, $errors ? ['errors' => $errors] : []);
    }

    // =========================================================================
    // 조회 메서드
    // =========================================================================

    /**
     * ID로 도메인 조회
     */
    public function findById(int $domainId): ?Domain
    {
        return $this->domainRepository->find($domainId);
    }

    /**
     * 도메인 ID 목록 → 각 도메인 소유자의 회원아이디(user_id) 맵
     *
     * 도메인 관리 목록에서 그룹 경로(예: 1/4/5)를 소유자 체인(admin/site/test1)으로
     * 표기하기 위해, 경로에 등장하는 모든 도메인의 소유자 아이디를 조회한다.
     * (도메인 회원은 회원 관리 목록에서 빠지므로 여기서 아이디를 노출한다.)
     *
     * 도메인 ID 목록 → 도메인명(hostname) 맵
     *
     * 회원 목록에서 독립(사이트개설)해나간 회원의 현재 운영 도메인명을 표시하는 용도.
     *
     * @param int[] $domainIds
     * @return array<int,string> [domain_id => domain]
     */
    public function getDomainNameMapByIds(array $domainIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $domainIds))));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->domainRepository->getDb()->table('domain_configs')
            ->whereRaw("domain_id IN ({$placeholders})", $ids)
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['domain_id']] = (string) ($r['domain'] ?? '');
        }
        return $map;
    }

    /**
     * @param int[] $domainIds 도메인 ID 목록 (그룹 경로의 각 단계 포함)
     * @return array<int,string> [domain_id => owner user_id]
     */
    public function getOwnerUserIdByDomainIds(array $domainIds): array
    {
        if (!$this->memberRepository) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $domainIds))));
        if (empty($ids)) {
            return [];
        }

        // domain_id => member_id (소유자)
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->domainRepository->getDb()->table('domain_configs')
            ->whereRaw("domain_id IN ({$placeholders})", $ids)
            ->get();

        $domainToMember = [];
        foreach ($rows as $r) {
            if (!empty($r['member_id'])) {
                $domainToMember[(int) $r['domain_id']] = (int) $r['member_id'];
            }
        }
        if (empty($domainToMember)) {
            return [];
        }

        // member_id => user_id
        $memberToUser = [];
        foreach ($this->memberRepository->findByIds(array_values($domainToMember)) as $member) {
            $memberToUser[$member->getMemberId()] = $member->getUserId();
        }

        // domain_id => user_id
        $map = [];
        foreach ($domainToMember as $domainId => $memberId) {
            if (isset($memberToUser[$memberId])) {
                $map[$domainId] = $memberToUser[$memberId];
            }
        }
        return $map;
    }

    /**
     * 도메인명으로 조회
     */
    public function findByDomain(string $domainName): ?Domain
    {
        return $this->domainRepository->findByDomain($domainName);
    }

    /**
     * 도메인 소유자 회원 정보 조회 (필드 값 포함)
     *
     * @param int $memberId 소유자 회원 ID
     * @return \Mublo\Entity\Member\Member|null
     */
    public function getOwnerMember(int $memberId): ?\Mublo\Entity\Member\Member
    {
        if (!$this->memberRepository) {
            return null;
        }

        $member = $this->memberRepository->find($memberId);
        if (!$member) {
            return null;
        }

        // 추가 필드 값 로드 (이름, 이메일 등) - 암호화된 필드는 복호화
        $fieldValuesRaw = $this->memberRepository->getFieldValues($memberId);
        $fieldValues = [];
        foreach ($fieldValuesRaw as $row) {
            $fieldName = $row['field_name'] ?? "field_{$row['field_id']}";
            $isEncrypted = (bool) ($row['is_encrypted'] ?? false);
            $rawValue = $row['field_value'] ?? null;

            // 암호화된 필드는 복호화하여 저장
            $fieldValues[$fieldName] = $this->encryptionService->readFieldValue(
                $rawValue,
                $isEncrypted
            );
        }
        $member->setFieldValues($fieldValues);

        return $member;
    }

    /**
     * 페이지네이션된 목록 조회
     *
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @param array $search 검색 조건
     * @param array $filters 필터 조건
     * @return array
     */
    public function getList(
        int $page = 1,
        int $perPage = 20,
        array $search = [],
        array $filters = []
    ): array {
        return $this->domainRepository->getPaginatedList($page, $perPage, $search, $filters);
    }

    // TODO: [리팩토링 3번] getStatusOptions →
    //       DomainStatus Enum으로 이동 (label(), options() 패턴)
    //       현재는 changeStatus, bulkChangeStatus 내부 검증에서도 사용 중

    /**
     * 상태 옵션 목록
     */
    public function getStatusOptions(): array
    {
        return [
            'active' => '활성',
            'inactive' => '비활성',
            'blocked' => '차단',
        ];
    }

    // =========================================================================
    // 소유자 검증 메서드
    // =========================================================================

    /**
     * 도메인 소유자 자격 검증
     *
     * 검증 항목:
     * 1. 회원 존재 여부
     * 2. can_operate_domain 권한 보유 여부 (등급 기반 — 도메인 운영 가능한 레벨)
     * 3. 등록자(관리자)와 같은 도메인 그룹 소속 여부
     * 4. 확장 정책 검증 (DomainOwnerValidatingEvent — 운영 수 제한 등
     *    상용/임대 정책은 코어가 아닌 패키지가 이 이벤트로 얹는다)
     *
     * Note: can_create_site는 생성 작업자(Controller)에서 체크. 소유자에게는 불필요.
     *
     * @param int $domainId 검색 대상 도메인 ID
     * @param string $userId 소유자 회원 아이디
     * @param string $adminDomainGroup 등록자(관리자)의 domain_group
     * @return Result
     */
    public function validateDomainOwner(int $domainId, string $userId, string $adminDomainGroup): Result
    {
        if (empty($userId)) {
            return $this->fail('소유자 회원 아이디를 입력해주세요.');
        }

        if (!$this->memberRepository) {
            return $this->fail('회원 저장소를 사용할 수 없습니다.');
        }

        // 1. 회원 존재 확인 (도메인 스코프 기반)
        $member = $this->memberRepository->findByDomainAndUserId($domainId, $userId);

        if (!$member) {
            return $this->fail('존재하지 않는 회원입니다.');
        }

        return $this->validateDomainOwnerCandidate($member, $domainId, $adminDomainGroup);
    }

    /**
     * 저장 요청에서 전달된 회원 ID를 기준으로 소유자 자격을 다시 검증한다.
     *
     * 브라우저의 사전 AJAX 검증은 우회할 수 있으므로 create()가 이 메서드를
     * 호출해 저장 직전에 동일한 권한·범위·확장 정책을 강제한다.
     */
    public function validateDomainOwnerById(int $domainId, int $memberId, string $adminDomainGroup): Result
    {
        if ($memberId <= 0) {
            return $this->fail('소유자 회원을 선택해주세요.');
        }

        if (!$this->memberRepository) {
            return $this->fail('회원 저장소를 사용할 수 없습니다.');
        }

        $member = $this->memberRepository->find($memberId);
        if (!$member instanceof Member) {
            return $this->fail('존재하지 않는 회원입니다.');
        }

        // AJAX의 findByDomainAndUserId()와 동일하게 현재 사이트 소속을 강제한다.
        // 그룹 접두사만 확인하면 하위 사이트 회원을 상위 사이트 소유자로 지정할 수 있다.
        if ($domainId > 0 && $member->getDomainId() !== $domainId) {
            return $this->fail('해당 회원은 현재 사이트 소속이 아닙니다.');
        }

        return $this->validateDomainOwnerCandidate($member, $domainId, $adminDomainGroup);
    }

    private function validateDomainOwnerCandidate(
        Member $member,
        int $domainId,
        string $adminDomainGroup
    ): Result {

        // 2. can_operate_domain 권한 확인 (등급 기반)
        if (!$member->canOperateDomain()) {
            $levelName = $member->getLevelName() ?? '알 수 없음';
            return $this->fail("도메인 운영 권한이 없는 등급입니다. (현재: {$levelName})");
        }

        // 3. 등록자와 같은 도메인 그룹 소속 확인
        $memberDomainGroup = $member->getDomainGroup() ?? '';

        // 도메인 그룹 계층 확인: adminDomainGroup이 memberDomainGroup의 상위 또는 같아야 함
        // 예: admin이 "1"이고 member가 "1" 또는 "1/2" 등이면 OK
        if (!empty($adminDomainGroup)) {
            // 그룹 정보가 없거나 관리자의 하위 범위가 아니면 저장 요청을 거부한다.
            if (empty($memberDomainGroup)
                || ($memberDomainGroup !== $adminDomainGroup
                    && strpos($memberDomainGroup, $adminDomainGroup . '/') !== 0)) {
                return $this->fail('해당 회원은 귀하의 관리 범위에 속하지 않습니다.');
            }
        }

        // 4. 확장 정책 검증 — 코어는 운영 수를 제한하지 않는다.
        //    운영 수 제한(요금제별 1사이트 등) 같은 상용 정책은 패키지가
        //    이 이벤트를 구독해 addError()로 거부한다.
        $validating = new DomainOwnerValidatingEvent(
            $member->getMemberId(),
            $member->getUserId(),
            $domainId,
            $adminDomainGroup
        );
        try {
            $this->dispatch($validating);
        } catch (\Exception $e) {
            error_log(sprintf(
                '[DomainService] owner_validation_failed member_id=%d domain_id=%d error=%s',
                $member->getMemberId(),
                $domainId,
                $e->getMessage()
            ));

            return $this->fail('사이트 운영 자격을 확인하지 못했습니다. 잠시 후 다시 시도해 주세요.');
        }

        if ($validating->hasErrors()) {
            return $this->fail($validating->getErrors()[0], $validating->getErrors());
        }

        // 모든 검증 통과
        return $this->success([
            'member_id' => $member->getMemberId(),
            'user_id' => $member->getUserId(),
            'level_name' => $member->getLevelName(),
            'level_type' => $member->getLevelType(),
        ], '사이트 소유자로 등록 가능합니다.');
    }

    // =========================================================================
    // CRUD 메서드
    // =========================================================================

    /**
     * 도메인 생성
     *
     * domain_group은 자동 생성됨: {parent_domain_group}/{new_domain_id}
     * member_id(소유자)는 필수
     *
     * @param array $data 도메인 데이터
     * @param string $parentDomainGroup 상위 도메인의 domain_group (등록자의 도메인 그룹)
     * @param int|null $createdBy 생성 작업자 회원 ID (소유자와 다를 수 있음 — 감사 추적용)
     */
    public function create(
        array $data,
        string $parentDomainGroup = '',
        ?int $createdBy = null,
        ?int $validationDomainId = null
    ): Result
    {
        // 소유자 회원 ID 필수 검증
        if (empty($data['member_id'])) {
            return $this->fail('소유자 회원을 선택해주세요.');
        }

        // 화면의 사전 검증 결과를 신뢰하지 않고 저장 경계에서 다시 검증한다.
        $ownerId = (int) $data['member_id'];
        $scopeDomainId = $validationDomainId ?? $this->lastDomainGroupId($parentDomainGroup);
        $ownerValidation = $this->validateDomainOwnerById($scopeDomainId, $ownerId, $parentDomainGroup);
        if ($ownerValidation->isFailure()) {
            return $ownerValidation;
        }

        // 도메인명 검증
        $validation = $this->validateDomainName($data['domain'] ?? '');
        if ($validation->isFailure()) {
            return $validation;
        }

        // 중복 체크 (포트 제거 후 호스트명 기준 비교)
        $hostOnly = $this->stripPort($data['domain']);
        if ($this->domainRepository->existsByDomain($hostOnly) ||
            $this->domainRepository->existsByDomain($data['domain'])) {
            return $this->fail('이미 등록된 도메인입니다.');
        }

        // 데이터 정리 (domain_group은 임시로 빈값)
        $insertData = $this->prepareInsertData($data);
        $insertData['domain_group'] = ''; // 먼저 빈값으로 저장
        $insertData['created_by'] = $createdBy;

        try {
            [$domainId, $newDomainGroup] = $this->domainRepository->getDb()->transaction(
                function () use ($insertData, $parentDomainGroup, $ownerId, $createdBy): array {
                    $domainId = $this->domainRepository->create($insertData);
                    if (!$domainId) {
                        throw new \RuntimeException('도메인 레코드를 생성하지 못했습니다.');
                    }

                    $newDomainGroup = $parentDomainGroup
                        ? $parentDomainGroup . '/' . $domainId
                        : (string) $domainId;

                    if ($this->domainRepository->update($domainId, ['domain_group' => $newDomainGroup]) < 1) {
                        throw new \RuntimeException('도메인 그룹을 갱신하지 못했습니다.');
                    }

                    if (!$this->memberRepository
                        || $this->memberRepository->update($ownerId, [
                            'domain_id' => $domainId,
                            'domain_group' => $newDomainGroup,
                        ]) < 1) {
                        throw new \RuntimeException('도메인 소유자 소속을 갱신하지 못했습니다.');
                    }

                    // 메뉴·약관·기본 블록은 생성 성공에 필요한 필수 데이터다.
                    $this->dispatch(new DomainProvisioningEvent(
                        $domainId,
                        $newDomainGroup,
                        $ownerId,
                        $createdBy
                    ));

                    return [$domainId, $newDomainGroup];
                }
            );
        } catch (\Exception $e) {
            error_log(sprintf(
                '[DomainService] domain_create_failed owner_id=%d parent_group=%s error=%s',
                $ownerId,
                $parentDomainGroup,
                $e->getMessage()
            ));

            return $this->fail('도메인 생성 중 문제가 발생했습니다. 잠시 후 다시 시도해 주세요.');
        }

        // 커밋 이후 확장 패키지·시작 킷 등 선택 후처리를 best-effort로 실행한다.
        $this->dispatch(new DomainCreatedEvent(
            $domainId,
            $newDomainGroup,
            $ownerId ?: null,
            $createdBy
        ));

        return $this->success(
            ['domain_id' => $domainId, 'domain_group' => $newDomainGroup],
            '도메인이 생성되었습니다.'
        );
    }

    /**
     * 도메인 수정
     */
    public function update(int $domainId, array $data): Result
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return $this->fail('도메인을 찾을 수 없습니다.');
        }

        // 도메인명 변경 시 검증
        if (isset($data['domain']) && $data['domain'] !== $domain->getDomain()) {
            $validation = $this->validateDomainName($data['domain']);
            if ($validation->isFailure()) {
                return $validation;
            }

            // 중복 체크 (포트 제거 후 호스트명 기준 비교, 자기 자신 제외)
            $hostOnly = $this->stripPort($data['domain']);
            if ($this->domainRepository->existsByDomainExcept($hostOnly, $domainId) ||
                $this->domainRepository->existsByDomainExcept($data['domain'], $domainId)) {
                return $this->fail('이미 등록된 도메인입니다.');
            }
        }

        // 데이터 정리
        $updateData = $this->prepareUpdateData($data);

        // 업데이트
        $this->domainRepository->update($domainId, $updateData);

        // 캐시 무효화 (기존 도메인명 + 새 도메인명)
        $this->invalidateCache($domain->getDomain());
        if (isset($data['domain']) && $data['domain'] !== $domain->getDomain()) {
            $this->invalidateCache($data['domain']);
        }

        // 도메인 수정 이벤트 발행
        $this->dispatch(new DomainUpdatedEvent($domainId, $domain, $updateData));

        return $this->success(
            ['domain_id' => $domainId],
            '도메인이 수정되었습니다.'
        );
    }

    /**
     * 도메인 삭제
     */
    public function delete(int $domainId): Result
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return $this->fail('도메인을 찾을 수 없습니다.');
        }

        // 기본 도메인(ID=1) 삭제 방지
        if ($domainId === 1) {
            return $this->fail('기본 도메인은 삭제할 수 없습니다.');
        }

        // 회원 존재 확인 (소유자 제외)
        $ownerId = $domain->getMemberId();
        if ($this->memberRepository) {
            $memberCount = $this->memberRepository->countByDomain($domainId);
            if ($ownerId) {
                $memberCount--; // 소유자 제외
            }
            if ($memberCount > 0) {
                return $this->fail("이 도메인에 {$memberCount}명의 회원이 있습니다. 먼저 회원을 삭제하거나 이동해주세요.");
            }
        }

        // 하위 도메인 존재 확인
        $children = $this->domainRepository->findChildren($domain->getDomainGroup());
        if (!empty($children)) {
            return $this->fail('하위 도메인이 있어 삭제할 수 없습니다. 먼저 하위 도메인을 삭제해주세요.');
        }

        // 소유자를 부모 도메인으로 이동
        if ($ownerId && $this->memberRepository) {
            $domainGroup = $domain->getDomainGroup() ?? '';
            $parentGroup = str_contains($domainGroup, '/')
                ? substr($domainGroup, 0, strrpos($domainGroup, '/'))
                : $domainGroup;
            $parentDomainId = (int) (str_contains($parentGroup, '/')
                ? substr($parentGroup, strrpos($parentGroup, '/') + 1)
                : $parentGroup);

            if ($parentDomainId > 0) {
                $this->memberRepository->update($ownerId, [
                    'domain_id' => $parentDomainId,
                    'domain_group' => $parentGroup,
                ]);
            }
        }

        // 삭제
        $this->domainRepository->delete($domainId);

        // 캐시 무효화
        $this->invalidateCache($domain->getDomain());

        // 도메인 삭제 이벤트 발행 (관련 데이터 정리용)
        $this->dispatch(new DomainDeletedEvent($domainId, $domain));

        return $this->success(null, '도메인이 삭제되었습니다.');
    }

    // =========================================================================
    // 상태 변경 메서드
    // =========================================================================

    /**
     * 단일 도메인 상태 변경
     */
    public function changeStatus(int $domainId, string $status): Result
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return $this->fail('도메인을 찾을 수 없습니다.');
        }

        // 유효한 상태값 확인
        $validStatuses = array_keys($this->getStatusOptions());
        if (!in_array($status, $validStatuses, true)) {
            return $this->fail('유효하지 않은 상태값입니다.');
        }

        // 기본 도메인(ID=1) 비활성화/차단 방지
        if ($domainId === 1 && $status !== 'active') {
            return $this->fail('기본 도메인은 비활성화하거나 차단할 수 없습니다.');
        }

        $this->domainRepository->update($domainId, ['status' => $status]);
        $this->invalidateCache($domain->getDomain());

        $statusLabel = $this->getStatusOptions()[$status] ?? $status;
        return $this->success(null, "도메인 상태가 '{$statusLabel}'으로 변경되었습니다.");
    }

    /**
     * 일괄 상태 변경
     */
    public function bulkChangeStatus(array $domainIds, string $status): Result
    {
        if (empty($domainIds)) {
            return $this->fail('선택된 도메인이 없습니다.');
        }

        // 유효한 상태값 확인
        $validStatuses = array_keys($this->getStatusOptions());
        if (!in_array($status, $validStatuses, true)) {
            return $this->fail('유효하지 않은 상태값입니다.');
        }

        // 기본 도메인(ID=1) 제외
        if ($status !== 'active' && in_array(1, $domainIds, true)) {
            return $this->fail('기본 도메인은 비활성화하거나 차단할 수 없습니다.');
        }

        // 변경 전 도메인 목록 조회 (캐시 무효화용)
        $domains = [];
        foreach ($domainIds as $id) {
            $domain = $this->domainRepository->find($id);
            if ($domain) {
                $domains[] = $domain;
            }
        }

        // 일괄 업데이트
        $affected = $this->domainRepository->updateStatus($domainIds, $status);

        // 캐시 무효화
        foreach ($domains as $domain) {
            $this->invalidateCache($domain->getDomain());
        }

        $statusLabel = $this->getStatusOptions()[$status] ?? $status;
        return $this->success(
            ['affected' => $affected],
            "{$affected}개 도메인의 상태가 '{$statusLabel}'으로 변경되었습니다."
        );
    }

    // =========================================================================
    // 검증 메서드
    // =========================================================================

    /**
     * 도메인명 검증
     */
    public function validateDomainName(string $domain): Result
    {
        $domain = trim($domain);

        if (empty($domain)) {
            return $this->fail('도메인명을 입력해주세요.');
        }

        // 포트 분리 (host:port 형식 허용) — 호스트명만 검증
        if (preg_match('/^(.+):(\d+)$/', $domain, $m)) {
            $port = (int) $m[2];
            if ($port < 1 || $port > 65535) {
                return $this->fail('유효하지 않은 포트 번호입니다.');
            }
            $domain = $m[1];
        }

        // 도메인 형식 검증 (간단한 정규식)
        // localhost, IP 주소, 일반 도메인 모두 허용
        if ($domain === 'localhost') {
            return $this->success();
        }

        // IP 주소 형식
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return $this->success();
        }

        // 도메인 형식 (영문, 숫자, 하이픈, 점)
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $domain)) {
            return $this->fail('유효하지 않은 도메인 형식입니다.');
        }

        // 길이 제한
        if (strlen($domain) > 253) {
            return $this->fail('도메인명은 253자를 초과할 수 없습니다.');
        }

        return $this->success();
    }

    /**
     * 도메인 중복 체크 (AJAX용)
     */
    public function checkDomainAvailability(string $domain, ?int $excludeId = null): Result
    {
        $validation = $this->validateDomainName($domain);
        if ($validation->isFailure()) {
            return $validation;
        }

        $domain = $this->stripPort($domain);

        $exists = $excludeId
            ? $this->domainRepository->existsByDomainExcept($domain, $excludeId)
            : $this->domainRepository->existsByDomain($domain);

        if ($exists) {
            return $this->fail('이미 등록된 도메인입니다.');
        }

        return $this->success(null, '사용 가능한 도메인입니다.');
    }

    // =========================================================================
    // 데이터 준비 메서드
    // =========================================================================

    /**
     * 생성용 데이터 준비
     */
    private function prepareInsertData(array $data): array
    {
        return [
            'domain' => strtolower(trim($data['domain'])),
            'domain_group' => trim($data['domain_group'] ?? ''),
            'member_id' => !empty($data['member_id']) ? (int)$data['member_id'] : null,
            'status' => $data['status'] ?? 'active',
            'storage_limit' => $this->parseStorageLimit($data['storage_limit'] ?? 1, $data['storage_unit'] ?? 'GB'),
            'member_limit' => (int)($data['member_limit'] ?? 0),
            'site_config' => json_encode($this->prepareSiteConfig($data), JSON_UNESCAPED_UNICODE),
            'company_config' => json_encode($this->prepareCompanyConfig($data), JSON_UNESCAPED_UNICODE),
            'seo_config' => json_encode($this->prepareSeoConfig($data), JSON_UNESCAPED_UNICODE),
            'theme_config' => json_encode($this->prepareThemeConfig($data), JSON_UNESCAPED_UNICODE),
            'extension_config' => json_encode($this->prepareExtensionConfig($data), JSON_UNESCAPED_UNICODE),
            'extra_config' => json_encode([], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * 수정용 데이터 준비
     */
    private function prepareUpdateData(array $data): array
    {
        $updateData = [];

        // 기본 필드
        $basicFields = [
            'domain' => fn($v) => strtolower(trim($v)),
            'domain_group' => fn($v) => trim($v),
            'status' => fn($v) => $v,
            'member_limit' => fn($v) => (int)$v,
        ];

        foreach ($basicFields as $field => $transform) {
            if (isset($data[$field])) {
                $updateData[$field] = $transform($data[$field]);
            }
        }

        // member_id (null 허용)
        if (array_key_exists('member_id', $data)) {
            $updateData['member_id'] = !empty($data['member_id']) ? (int)$data['member_id'] : null;
        }

        // 저장공간 제한
        if (isset($data['storage_limit'])) {
            $updateData['storage_limit'] = $this->parseStorageLimit(
                $data['storage_limit'],
                $data['storage_unit'] ?? 'GB'
            );
        }

        return $updateData;
    }

    /**
     * 저장공간 제한 파싱 (GB/MB → bytes)
     */
    private function parseStorageLimit(int|float|string $value, string $unit): int
    {
        $value = (float)$value;

        return match (strtoupper($unit)) {
            'TB' => (int)($value * 1099511627776),
            'GB' => (int)($value * 1073741824),
            'MB' => (int)($value * 1048576),
            'KB' => (int)($value * 1024),
            default => (int)$value,
        };
    }

    /**
     * 사이트 설정 준비 (도메인 생성용)
     *
     * Note: logo, favicon은 seo_config에서 관리
     * @see DomainSettingsService::SITE_KEYS
     */
    private function prepareSiteConfig(array $data): array
    {
        return [
            // 하위 사이트 생성 폼은 사이트명을 받지 않으므로 빈 값이면 기본 문자열을 넣는다.
            // (운영자가 관리자 설정에서 변경 전까지 프론트 로고가 '.' 단독으로 뜨는 것 방지)
            'site_title' => trim($data['site_title'] ?? '') ?: 'MUBLO',
            'site_subtitle' => trim($data['site_subtitle'] ?? ''),
            'admin_email' => trim($data['admin_email'] ?? ''),
            'timezone' => $data['timezone'] ?? 'Asia/Seoul',
            'language' => $data['language'] ?? 'ko',
            'editor' => $data['editor'] ?? 'mublo-editor',
            'per_page' => (int) ($data['per_page'] ?? 20),
            // 레이아웃 기본 폭: 신규 사이트는 1200px 고정폭으로 시작.
            // (미설정 시 프론트 테마가 --site-max-width: none 으로 wide 렌더됨)
            'layout_max_width' => (int) ($data['layout_max_width'] ?? 1200),
        ];
    }

    /**
     * 회사 정보 준비 (도메인 생성용)
     *
     * @see DomainSettingsService::COMPANY_KEYS
     */
    private function prepareCompanyConfig(array $data): array
    {
        return [
            'name' => trim($data['name'] ?? ''),
            'owner' => trim($data['owner'] ?? ''),
            'tel' => trim($data['tel'] ?? ''),
            'fax' => trim($data['fax'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'business_number' => trim($data['business_number'] ?? ''),
            'tongsin_number' => trim($data['tongsin_number'] ?? ''),
            'zipcode' => trim($data['zipcode'] ?? ''),
            'address' => trim($data['address'] ?? ''),
            'address_detail' => trim($data['address_detail'] ?? ''),
            'privacy_officer' => trim($data['privacy_officer'] ?? ''),
            'privacy_email' => trim($data['privacy_email'] ?? ''),
            // 하위 사이트 생성 폼은 고객센터 번호를 받지 않으므로 빈 값이면 기본 번호를 넣는다.
            // (운영자가 관리자 설정에서 변경 전까지 푸터 오른쪽 고객센터 대표번호가 비는 것 방지)
            'cs_tel' => trim($data['cs_tel'] ?? '') ?: '0000-0000',
            'cs_time' => trim($data['cs_time'] ?? ''),
        ];
    }

    /**
     * SEO 설정 준비 (도메인 생성용)
     *
     * @see DomainSettingsService::SEO_KEYS
     */
    private function prepareSeoConfig(array $data): array
    {
        return [
            'logo_pc' => trim($data['logo_pc'] ?? ''),
            'logo_mobile' => trim($data['logo_mobile'] ?? ''),
            'favicon' => trim($data['favicon'] ?? ''),
            'app_icon' => trim($data['app_icon'] ?? ''),
            'og_image' => trim($data['og_image'] ?? ''),
            'meta_title' => trim($data['meta_title'] ?? ''),
            'meta_description' => trim($data['meta_description'] ?? ''),
            'meta_keywords' => trim($data['meta_keywords'] ?? ''),
            'google_analytics' => trim($data['google_analytics'] ?? ''),
            'google_site_verification' => trim($data['google_site_verification'] ?? ''),
            'naver_site_verification' => trim($data['naver_site_verification'] ?? ''),
            'sns_channels' => $data['sns_channels'] ?? [],
        ];
    }

    /**
     * 테마 설정 준비 (도메인 생성용)
     *
     * @see DomainSettingsService::THEME_KEYS
     */
    private function prepareThemeConfig(array $data): array
    {
        $defaultTheme = 'basic';
        return [
            'admin' => $data['admin'] ?? $defaultTheme,
            'frame' => $data['frame'] ?? $defaultTheme,
            'index' => $data['index'] ?? $defaultTheme,
            'board' => $data['board'] ?? $defaultTheme,
            'member' => $data['member'] ?? $defaultTheme,
            'auth' => $data['auth'] ?? $defaultTheme,
            'page' => $data['page'] ?? $defaultTheme,
        ];
    }

    /**
     * 확장 기능 설정 준비
     *
     * default:true 패키지를 자동 포함하여 새 도메인 생성 시에도
     * 기본 패키지가 활성화·설치 상태로 시작되도록 함.
     */
    private function prepareExtensionConfig(array $data): array
    {
        $plugins = $data['plugins'] ?? [];
        $packages = $data['packages'] ?? [];

        // default:true 패키지 자동 포함
        $defaultPackages = $this->getDefaultPackageNames();
        foreach ($defaultPackages as $pkg) {
            if (!in_array($pkg, $packages, true)) {
                $packages[] = $pkg;
            }
        }

        // installed에는 선마킹하지 않는다 — 시딩은 install() 단일 경로로 통합돼 있고,
        // 첫 요청의 reconcileDefaultExtensions()가 활성 목록의 미설치 확장을 정식 설치한다.
        // (여기서 installed로 미리 마킹하면 reconcile이 건너뛰어 install()이 영영 안 돈다.)
        return [
            'plugins' => $plugins,
            'packages' => $packages,
            'installed' => [
                'plugins' => [],
                'packages' => [],
            ],
        ];
    }

    /**
     * manifest.json에서 default:true인 패키지명 목록
     */
    private function getDefaultPackageNames(): array
    {
        if (!$this->extensionService) {
            return [];
        }

        $defaults = [];
        foreach ($this->extensionService->getPackageManifests() as $name => $manifest) {
            if (!empty($manifest['default'])) {
                $defaults[] = $name;
            }
        }

        return $defaults;
    }

    // =========================================================================
    // 캐시 관리
    // =========================================================================

    /**
     * 도메인명에서 포트 제거 (test.localhost:8080 → test.localhost)
     */
    private function stripPort(string $domain): string
    {
        if (preg_match('/^(.+):\d+$/', $domain, $m)) {
            return $m[1];
        }
        return $domain;
    }

    /**
     * 도메인 그룹 경로의 마지막 ID를 반환한다. 명시적 검증 도메인 ID가 없는
     * 기존 create() 호출의 하위 호환용이며, 컨트롤러는 현재 도메인 ID를 전달한다.
     */
    private function lastDomainGroupId(string $domainGroup): int
    {
        $parts = array_values(array_filter(explode('/', trim($domainGroup, '/')), 'strlen'));
        return $parts ? (int) end($parts) : 0;
    }

    /**
     * 캐시 무효화 (메모리 + 파일 캐시 모두 삭제)
     */
    private function invalidateCache(string $domainName): void
    {
        $this->domainResolver->invalidate($domainName);
    }
}
