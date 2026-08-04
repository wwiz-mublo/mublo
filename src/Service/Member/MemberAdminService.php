<?php
declare(strict_types=1);
namespace Mublo\Service\Member;

use Mublo\Repository\Member\MemberRepository;
use Mublo\Repository\Member\MemberFieldRepository;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Entity\Member\Member;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Service\Member\Event\MemberRegisteredByAdminEvent;
use Mublo\Service\Member\Event\MemberUpdatedByAdminEvent;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Service\CustomField\CustomFieldValidator;
use Mublo\Service\CustomField\CustomFieldFileHandler;

/**
 * MemberAdminService
 *
 * 관리자용 회원 관리 비즈니스 로직
 *
 * 책임:
 * - 관리자에 의한 회원 등록/수정/삭제
 * - 회원 목록 조회 및 검색
 * - 목록용 필드 값 조회
 *
 * 참고:
 * - 검증 로직은 MemberService에 위임
 * - 필드 값 저장/조회는 MemberService 활용
 */
class MemberAdminService
{
    private MemberRepository $memberRepository;
    private MemberFieldRepository $fieldRepository;
    private FieldEncryptionService $encryptionService;
    private MemberService $memberService;
    private ?MemberFieldService $fieldService = null;
    private ?MemberLevelRepository $levelRepository = null;
    private ?EventDispatcher $eventDispatcher = null;
    private ?SecureFileService $secureFileService = null;

    public function __construct(
        MemberRepository $memberRepository,
        MemberFieldRepository $fieldRepository,
        FieldEncryptionService $encryptionService,
        MemberService $memberService,
        ?MemberFieldService $fieldService = null,
        ?MemberLevelRepository $levelRepository = null,
        ?EventDispatcher $eventDispatcher = null,
        ?SecureFileService $secureFileService = null
    ) {
        $this->memberRepository = $memberRepository;
        $this->fieldRepository = $fieldRepository;
        $this->encryptionService = $encryptionService;
        $this->memberService = $memberService;
        $this->fieldService = $fieldService;
        $this->levelRepository = $levelRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->secureFileService = $secureFileService;
    }

    // =========================================================================
    // 회원 등록/수정/삭제 (Admin Only)
    // =========================================================================

    /**
     * 관리자용 회원 등록
     *
     * 일반 register와 차이점:
     * - level_value 직접 지정 가능
     * - status 직접 지정 가능
     * - domain_group 상위 관리자로부터 상속
     *
     * 추가 데이터 키:
     * - admin_id: 등록하는 관리자 ID
     * - admin_is_super: 현재 관리자가 최고관리자인지 여부
     * - admin_level_type: 현재 관리자의 레벨 타입 (STAFF, PARTNER 등)
     * - admin_domain_group: 현재 관리자의 domain_group (하위 회원에 상속)
     */
    public function register(array $data): Result
    {
        // 필수 필드 검증
        $required = ['user_id', 'password', 'domain_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return Result::failure("{$field}은(는) 필수 입력 항목입니다.");
            }
        }

        // 아이디 검증 (형식 + 중복)
        $userIdCheck = $this->memberService->checkUserIdAvailability($data['domain_id'], $data['user_id']);
        if ($userIdCheck->isFailure()) {
            return $userIdCheck;
        }

        // 비밀번호 검증 (도메인 정책 적용)
        $passwordCheck = $this->memberService->validatePassword($data['password'], (int) ($data['domain_id'] ?? 0) ?: null);
        if ($passwordCheck->isFailure()) {
            return $passwordCheck;
        }

        // 레벨 할당 권한 검증 (항상 수행 — 슈퍼/자기보다 높은 등급 지정 차단, fail-closed)
        $targetLevelValue = (int) ($data['level_value'] ?? 1);
        $levelCheck = $this->memberService->validateLevelAssignment(
            $data['domain_id'],
            $targetLevelValue,
            (bool) ($data['admin_is_super'] ?? false),
            (int) ($data['admin_level_value'] ?? 0)
        );
        if ($levelCheck->isFailure()) {
            return $levelCheck;
        }

        // 추가 필드 검증 (관리자 경로 — admin_only 필드 허용)
        if (!empty($data['fields'])) {
            $fieldValidation = $this->memberService->validateFieldValues($data['domain_id'], $data['fields'], true, null, true);
            if ($fieldValidation->isFailure()) {
                return $fieldValidation;
            }
        }

        // 비밀번호 해시
        $hashedPassword = $this->memberService->getPasswordHasher()->hash($data['password']);

        // 닉네임 검증
        $nickname = trim($data['nickname'] ?? '');
        if (!empty($nickname)) {
            $nicknameCheck = $this->memberService->validateNickname($nickname);
            if ($nicknameCheck->isFailure()) {
                return $nicknameCheck;
            }

            $nicknameDup = $this->memberService->checkDuplicate($data['domain_id'], 'nickname', $nickname);
            if ($nicknameDup->isFailure()) {
                return $nicknameDup;
            }
        }

        // 회원 생성 데이터
        $createData = [
            'domain_id' => $data['domain_id'],
            // 최초 가입 도메인 = 생성 도메인(불변). 사이트 개설로 domain_id가 바뀌어도 유지된다.
            'origin_domain_id' => $data['domain_id'],
            'user_id' => $data['user_id'],
            'password' => $hashedPassword,
            'level_value' => $targetLevelValue,
            'status' => $data['status'] ?? 'active',
        ];

        if (!empty($nickname)) {
            $createData['nickname'] = $nickname;
        }

        // domain_group 상속 (상위 관리자의 domain_group을 그대로 사용)
        if (!empty($data['admin_domain_group'])) {
            $createData['domain_group'] = $data['admin_domain_group'];
        }

        // 회원 생성 + 추가 필드 저장 (원자적 — 필드 유일성 충돌 시 회원 생성도 롤백)
        try {
            $memberId = $this->memberRepository->getDb()->transaction(function () use ($createData, $data) {
                $memberId = $this->memberRepository->create($createData);
                if (!$memberId) {
                    throw new \RuntimeException('회원 등록에 실패했습니다.');
                }

                if (!empty($data['fields'])) {
                    $this->memberService->saveFieldValuesForMember($memberId, $data['fields'], (int) $data['domain_id'], true);
                }

                return $memberId;
            });
        } catch (\Mublo\Service\Member\Exception\DuplicateFieldValueException $e) {
            return Result::failure($e->getMessage());
        } catch (\RuntimeException $e) {
            return Result::failure('회원 등록에 실패했습니다.');
        }

        // 이벤트 발행: 관리자에 의한 회원 등록
        $member = $this->memberRepository->find($memberId);
        if ($member) {
            $adminId = $data['admin_id'] ?? 0;
            $this->dispatch(new MemberRegisteredByAdminEvent($member, $adminId));
        }

        return Result::success('회원이 등록되었습니다.', ['member_id' => $memberId]);
    }

    /**
     * 관리자용 회원 정보 수정
     *
     * 일반 update와 차이점:
     * - level_value 변경 가능
     * - status 변경 가능
     *
     * 추가 데이터 키:
     * - admin_id: 수정하는 관리자 ID
     * - admin_is_super: 현재 관리자가 최고관리자인지 여부
     * - admin_level_type: 현재 관리자의 레벨 타입 (STAFF, PARTNER 등)
     */
    public function update(int $memberId, array $data): Result
    {
        $member = $this->memberRepository->find($memberId);

        if (!$member) {
            return Result::failure('회원 정보를 찾을 수 없습니다.');
        }

        // 도메인 경계 검증: 슈퍼가 아니면 자기 사이트 회원만 수정 가능.
        // "자기 사이트 회원" = 현재 소속(domain_id) OR 이 사이트에서 태어나 독립해나간 회원(origin_domain_id).
        // 사이트를 개설해 떠난 회원도 태생 사이트 관리자가 회원처럼 관리할 수 있어야 한다.
        if (!empty($data['admin_domain_id']) && !($data['admin_is_super'] ?? false)) {
            $adminDomainId = (int) $data['admin_domain_id'];
            if ($member->getDomainId() !== $adminDomainId && $member->getOriginDomainId() !== $adminDomainId) {
                return Result::failure('수정 권한이 없습니다.');
            }
        }

        // 최고관리자 보호: 최고관리자 계정은 최고관리자만 수정할 수 있다.
        // 도메인 소유자(비-슈퍼)도 자기 도메인 경계 안의 슈퍼 계정은 건드릴 수 없다 —
        // delete() 의 슈퍼 차단(아래 삭제 경로)과 대칭. 이 가드가 없으면 아래 '관리자 계정 보호'가
        // 도메인 소유자에게는 통과되어 슈퍼 계정 수정 경로가 열린다.
        if ($member->isSuper() && empty($data['admin_is_super'])) {
            return Result::failure('최고관리자 정보는 최고관리자만 수정할 수 있습니다.');
        }

        // 관리자 계정 보호: 관리자 레벨은 상하 서열이 아니므로 '상위/하위'가 아니라
        // '관리자 여부'로 차단한다. 다른 관리자를 수정할 수 있는 주체는 (1) 슈퍼,
        // (2) 해당 도메인의 주인(domain_configs.member_id 소유자)뿐이다. 그 외 관리자는
        // 본인만 수정 가능 — 그렇지 않으면 A 관리자가 B 관리자의 비밀번호·상태를 바꿔 계정을
        // 탈취/잠금할 수 있다. 플래그 부재 시 비-슈퍼·비-소유·타인으로 취급(fail-closed).
        if ($member->isAdmin()
            && empty($data['admin_is_super'])
            && !$this->isDomainOwnerAdmin($data)
        ) {
            if ((int) ($data['admin_id'] ?? 0) !== $member->getMemberId()) {
                return Result::failure('다른 관리자의 정보는 최고관리자 또는 도메인 주인만 수정할 수 있습니다.');
            }
        }

        $updateData = [];
        $changes = [];

        // 비밀번호 변경 (빈 값이면 변경 안함)
        if (!empty($data['password'])) {
            $passwordCheck = $this->memberService->validatePassword($data['password'], $member->getDomainId());
            if ($passwordCheck->isFailure()) {
                return $passwordCheck;
            }
            $updateData['password'] = $this->memberService->getPasswordHasher()->hash($data['password']);
            $changes[] = 'password';
        }

        // 닉네임 변경
        if (isset($data['nickname'])) {
            $nickname = trim($data['nickname']);
            if ($nickname !== '') {
                $nicknameCheck = $this->memberService->validateNickname($nickname);
                if ($nicknameCheck->isFailure()) {
                    return $nicknameCheck;
                }

                $nicknameDup = $this->memberService->checkDuplicate($member->getDomainId(), 'nickname', $nickname, $memberId);
                if ($nicknameDup->isFailure()) {
                    return $nicknameDup;
                }

                $updateData['nickname'] = $nickname;
                $changes[] = 'nickname';
            }
        }

        // 등급 변경
        if (isset($data['level_value']) && $data['level_value'] !== null && $data['level_value'] !== '') {
            $targetLevelValue = (int) $data['level_value'];

            // 본인의 등급은 변경 불가 (셀프 강등으로 인한 권한 상실·마지막 슈퍼 브릭 방지).
            // 등급이 실제로 바뀔 때만 차단하므로, 본인의 다른 정보 수정은 정상 허용된다.
            if (!empty($data['admin_id'])
                && (int) $data['admin_id'] === $member->getMemberId()
                && $targetLevelValue !== $member->getLevelValue()) {
                return Result::failure('본인의 등급은 변경할 수 없습니다.');
            }

            // 레벨 할당 권한 검증 (항상 수행 — 슈퍼/자기보다 높은 등급 지정 차단, fail-closed)
            $levelCheck = $this->memberService->validateLevelAssignment(
                $member->getDomainId(),
                $targetLevelValue,
                (bool) ($data['admin_is_super'] ?? false),
                (int) ($data['admin_level_value'] ?? 0)
            );
            if ($levelCheck->isFailure()) {
                return $levelCheck;
            }

            $updateData['level_value'] = $targetLevelValue;
            if ($member->getLevelValue() !== $targetLevelValue) {
                $changes[] = 'level_value';
            }
        }

        // 상태 변경
        if (!empty($data['status'])) {
            $allowedStatuses = ['active', 'inactive', 'dormant', 'blocked'];
            if (in_array($data['status'], $allowedStatuses, true)) {
                $updateData['status'] = $data['status'];
                if ($member->getStatus()->value !== $data['status']) {
                    $changes[] = 'status';
                }
            }
        }

        // 사이트 생성 권한 변경 (슈퍼관리자만 변경 가능)
        if (isset($data['can_create_site']) && ($data['admin_is_super'] ?? false)) {
            $canCreateSite = (int) (bool) $data['can_create_site'];
            $updateData['can_create_site'] = $canCreateSite;
            $currentFlag = (int) ($member->toArray()['can_create_site'] ?? 0);
            if ($currentFlag !== $canCreateSite) {
                $changes[] = 'can_create_site';
            }
        }

        // Core 필드 + 추가 필드를 트랜잭션으로 묶어 원자적 업데이트
        $db = $this->memberRepository->getDb();
        $db->beginTransaction();

        try {
            // Core 필드 업데이트
            if (!empty($updateData)) {
                $this->memberRepository->update($memberId, $updateData);
            }

            // 추가 필드 검증 및 저장 (유일성 판정에서 본인 값 제외, 관리자 경로 — admin_only 허용)
            if (!empty($data['fields'])) {
                $domainId = $member->getDomainId();
                $fieldValidation = $this->memberService->validateFieldValues($domainId, $data['fields'], false, $memberId, true);
                if ($fieldValidation->isFailure()) {
                    $db->rollBack();
                    return $fieldValidation;
                }

                $this->memberService->saveFieldValuesForMember($memberId, $data['fields'], $domainId, true);
                $changes[] = 'fields';
            }

            $db->commit();
        } catch (\Mublo\Service\Member\Exception\DuplicateFieldValueException $e) {
            $db->rollBack();
            return Result::failure($e->getMessage());
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // 이벤트 발행: 관리자에 의한 회원 정보 수정
        if (!empty($changes)) {
            $adminId = $data['admin_id'] ?? 0;
            $this->dispatch(new MemberUpdatedByAdminEvent($member, $changes, $adminId));
        }

        return Result::success('회원정보가 수정되었습니다.');
    }

    /**
     * 요청 관리자가 '해당 도메인의 주인'인지 판별 — 소유권(domain_configs.member_id) 기준.
     *
     * 도메인 주인 판정은 등급(레벨)이 아니라 실제 소유 원장으로만 한다: admin_domain_id 도메인 행의
     * member_id가 요청 관리자(admin_id)와 일치하는가. 등급(can_operate_domain)은 "운영 가능한 레벨인가"일
     * 뿐 "이 특정 도메인의 주인인가"를 증명하지 못하므로 판정에 쓰지 않는다.
     *
     * 도메인 경계 검증(update/delete의 admin_domain_id 대조)이 대상 회원을 이 도메인으로
     * 이미 한정하므로, "요청자가 이 도메인의 소유자"임을 확인하면 "대상 도메인의 주인"이 성립한다.
     *
     * @param array $ctx admin_id, admin_domain_id 를 담은 컨텍스트
     */
    private function isDomainOwnerAdmin(array $ctx): bool
    {
        $adminId = (int) ($ctx['admin_id'] ?? 0);
        $domainId = (int) ($ctx['admin_domain_id'] ?? 0);

        return $adminId > 0
            && $domainId > 0
            && $this->memberService->ownsDomainId($adminId, $domainId);
    }

    /**
     * 관리자용 회원 삭제
     *
     * 비밀번호 확인 없이 삭제 (관리자 권한)
     *
     * @param int $memberId 삭제할 회원 ID
     * @param array $adminContext 관리자 컨텍스트 [
     *     'admin_domain_id' => int,        // 현재 관리자의 도메인 ID
     *     'admin_is_super' => bool,        // 최고관리자 여부
     * ]
     */
    public function delete(int $memberId, array $adminContext = []): Result
    {
        $member = $this->memberRepository->find($memberId);

        if (!$member) {
            return Result::failure('회원 정보를 찾을 수 없습니다.');
        }

        // 슈퍼관리자는 삭제 불가 (보호)
        if ($member->isSuper()) {
            return Result::failure('최고관리자 계정은 삭제할 수 없습니다.');
        }

        // 관리자 계정 보호: 관리자 레벨은 상하 서열이 아니므로, 다른 관리자를 삭제할 수 있는
        // 주체는 슈퍼 또는 해당 도메인의 주인(domain_configs.member_id 소유자)뿐이다(update와 동일 정책 —
        // 삭제는 계정 탈취보다 파괴적). 그 외 관리자는 다른 관리자를 삭제할 수 없다.
        if ($member->isAdmin()
            && !($adminContext['admin_is_super'] ?? false)
            && !$this->isDomainOwnerAdmin($adminContext)
        ) {
            return Result::failure('다른 관리자 계정은 최고관리자 또는 도메인 주인만 삭제할 수 있습니다.');
        }

        // 도메인 경계 검증: 슈퍼가 아니면 자기 사이트 회원만 삭제 가능.
        // "자기 사이트 회원" = 현재 소속(domain_id) OR 태생(origin_domain_id) — 독립해나간 회원 포함.
        if (!empty($adminContext['admin_domain_id']) && !($adminContext['admin_is_super'] ?? false)) {
            $adminDomainId = (int) $adminContext['admin_domain_id'];
            if ($member->getDomainId() !== $adminDomainId && $member->getOriginDomainId() !== $adminDomainId) {
                return Result::failure('삭제 권한이 없습니다.');
            }
        }

        // 도메인 운영자(사이트 개설 회원) 하드삭제 차단: domain_configs.member_id가 고아가 되는 것을 막는다.
        // 사이트 관리에서 도메인을 먼저 삭제하면 소유자는 상위로 복귀해 일반 회원처럼 삭제할 수 있다.
        // (withdraw()의 운영자 탈퇴 차단과 대칭)
        if ($this->memberService->ownsDomain($memberId)) {
            return Result::failure('운영 중인 사이트가 있어 삭제할 수 없습니다. 사이트 관리에서 해당 도메인을 먼저 삭제해주세요.');
        }

        $adminId = (int) ($adminContext['admin_id'] ?? 0);

        // 이벤트: 삭제 진행 (차단 가능)
        $deletingEvent = new \Mublo\Service\Member\Event\MemberDeletingEvent($member, $adminId);
        $this->dispatch($deletingEvent);
        if ($deletingEvent->isBlocked()) {
            return Result::failure($deletingEvent->getBlockReason() ?? '회원 삭제가 차단되었습니다.');
        }

        // 파일형 필드 메타는 삭제 전에 확보 (FK CASCADE 로 field_values 가 사라지므로)
        $fileMetas = $this->memberService->collectMemberFileMetas($memberId);

        // 회원 삭제 (FK CASCADE로 관련 데이터 자동 삭제)
        $this->memberRepository->delete($memberId);

        // 디스크 파일 정리 (실패는 로그만 — 삭제 흐름 비차단)
        $this->memberService->deleteFilesByMetas($fileMetas, $memberId);

        // 이벤트: 삭제 완료 (readonly — member 는 삭제 전 스냅샷)
        $this->dispatch(new \Mublo\Service\Member\Event\MemberDeletedEvent($member, $adminId));

        return Result::success('회원이 삭제되었습니다.');
    }

    // =========================================================================
    // 목록 조회 (Admin용)
    // =========================================================================

    /**
     * 회원 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @param array $search 검색 조건 ['keyword' => '', 'field' => '', 'field_info' => array|null]
     * @param string|null $domainGroup 계층 범위 조회용 domain_group (null이면 단일 도메인)
     */
    public function getList(int $domainId, int $page = 1, int $perPage = 10, array $search = [], ?string $domainGroup = null, ?string $sort = null, ?string $order = null, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;
        $useHierarchy = $domainGroup !== null;

        if (!empty($search['keyword']) && empty($search['field'])) {
            // 필드 미선택 → 아이디/닉네임 통합검색(OR). 슈퍼(계층)면 하위 전체 검색
            if ($useHierarchy) {
                $members = $this->memberRepository->searchByDomainGroupAny($domainGroup, $search['keyword'], $perPage, $offset, $sort, $order, $filters);
                $total = $this->memberRepository->countByDomainGroupAny($domainGroup, $search['keyword'], $filters);
            } else {
                // 비-슈퍼: 현재 소속 + 태생(origin) 회원 포함(includeOrigin=true) — 독립해나간 회원도 관리
                $members = $this->memberRepository->searchByDomainAny($domainId, $search['keyword'], $perPage, $offset, $sort, $order, $filters, true);
                $total = $this->memberRepository->countByDomainAny($domainId, $search['keyword'], $filters, true);
            }
        } elseif (!empty($search['keyword']) && !empty($search['field'])) {
            $fieldInfo = $search['field_info'] ?? null;
            $searchIndex = null;

            if ($fieldInfo !== null && $fieldInfo['is_encrypted']) {
                $searchIndex = $this->encryptionService->createSearchIndex($search['keyword']);
            }

            if ($useHierarchy) {
                $members = $this->memberRepository->searchByDomainGroupWithField(
                    $domainGroup,
                    $search,
                    $searchIndex,
                    $perPage,
                    $offset,
                    $sort,
                    $order,
                    $filters
                );
                $total = $this->memberRepository->countByDomainGroupWithFieldSearch(
                    $domainGroup,
                    $search,
                    $searchIndex,
                    $filters
                );
            } else {
                // 비-슈퍼: 현재 소속 + 태생(origin) 회원 포함(includeOrigin=true)
                $members = $this->memberRepository->searchByDomainWithField(
                    $domainId,
                    $search,
                    $searchIndex,
                    $perPage,
                    $offset,
                    $sort,
                    $order,
                    $filters,
                    true
                );
                $total = $this->memberRepository->countByDomainWithFieldSearch(
                    $domainId,
                    $search,
                    $searchIndex,
                    $filters,
                    true
                );
            }
        } else {
            if ($useHierarchy) {
                $members = $this->memberRepository->findByDomainGroup($domainGroup, $perPage, $offset, $sort, $order, $filters);
                $total = $this->memberRepository->countByDomainGroup($domainGroup, $filters);
            } else {
                // 비-슈퍼: 현재 소속 + 태생(origin) 회원 포함(includeOrigin=true)
                $members = $this->memberRepository->findByDomain($domainId, $perPage, $offset, $sort, $order, $filters, true);
                $total = $this->memberRepository->countByDomain($domainId, $filters, true);
            }
        }

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

        return [
            'success' => true,
            'data' => [
                'members' => $members,
                'total' => $total,
                'pagination' => [
                    'currentPage' => $page,
                    'perPage' => $perPage,
                    'totalPages' => $totalPages,
                    'totalItems' => $total,
                ],
            ],
        ];
    }

    /**
     * 검색 필드 목록 조회 (members 컬럼 + 추가 필드)
     *
     * @return array [field_name => ['label' => string, 'field_info' => array|null], ...]
     */
    public function getSearchFields(int $domainId): array
    {
        $fields = [
            'user_id' => ['label' => '아이디', 'field_info' => null],
            'nickname' => ['label' => '닉네임', 'field_info' => null],
        ];

        if ($this->fieldService) {
            $searchableFields = $this->fieldService->getSearchableFields($domainId);
            foreach ($searchableFields as $fieldName => $info) {
                $fields[$fieldName] = [
                    'label' => $info['field_label'],
                    'field_info' => [
                        'field_id' => $info['field_id'],
                        'is_encrypted' => $info['is_encrypted'],
                    ],
                ];
            }
        }

        return $fields;
    }

    /**
     * 회원 목록 조회 (추가 필드 포함)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @param array $search 검색 조건
     * @param string|null $domainGroup 계층 범위 조회용 domain_group (null이면 단일 도메인)
     * @return array 목록, 목록 표시 필드, 페이지네이션
     */
    /**
     * 정렬 파라미터 정규화 (View 헤더 표시용)
     *
     * @return array{0:string,1:string} [컬럼, 방향] 미허용 시 기본값 [member_id, DESC]
     */
    public function normalizeSort(?string $sort, ?string $order): array
    {
        return $this->memberRepository->resolveSort($sort, $order);
    }

    public function getListWithFields(int $domainId, int $page = 1, int $perPage = 10, array $search = [], ?string $domainGroup = null, ?string $sort = null, ?string $order = null, array $filters = []): array
    {
        $result = $this->getList($domainId, $page, $perPage, $search, $domainGroup, $sort, $order, $filters);

        if (!$result['success']) {
            return $result;
        }

        $members = $result['data']['members'];

        $listFields = [];
        if ($this->fieldService) {
            $listFields = $this->fieldService->getListVisibleFields($domainId);
        }

        $memberIds = array_map(fn($m) => $m->getMemberId(), $members);

        $fieldValuesMap = [];
        if (!empty($memberIds) && !empty($listFields)) {
            $fieldValuesMap = $this->getFieldValuesForMembers($memberIds, $listFields);
        }

        $membersWithFields = [];
        foreach ($members as $member) {
            $memberData = $member->toArray();
            $memberId = $member->getMemberId();

            foreach ($listFields as $field) {
                $fieldName = $field['field_name'];
                $fieldId = $field['field_id'];
                $memberData['field_' . $fieldName] = $fieldValuesMap[$memberId][$fieldId] ?? '';
            }

            $membersWithFields[] = $memberData;
        }

        return [
            'success' => true,
            'data' => [
                'members' => $membersWithFields,
                'listFields' => $listFields,
                'total' => $result['data']['total'],
                'pagination' => $result['data']['pagination'],
            ],
        ];
    }

    /**
     * 여러 회원의 필드 값 일괄 조회
     *
     * @param array $memberIds 회원 ID 목록
     * @param array $fields 조회할 필드 정의
     * @return array [member_id => [field_id => value, ...], ...]
     */
    private function getFieldValuesForMembers(array $memberIds, array $fields): array
    {
        if (empty($memberIds) || empty($fields)) {
            return [];
        }

        $fieldIds = array_column($fields, 'field_id');

        $fieldMetaMap = [];
        foreach ($fields as $field) {
            $fieldMetaMap[$field['field_id']] = [
                'is_encrypted' => (bool) ($field['is_encrypted'] ?? false),
                'field_type' => $field['field_type'] ?? 'text',
            ];
        }

        $values = $this->memberRepository->getFieldValuesForMembers($memberIds, $fieldIds);

        $result = [];
        foreach ($values as $row) {
            $memberId = $row['member_id'];
            $fieldId = $row['field_id'];
            $meta = $fieldMetaMap[$fieldId] ?? ['is_encrypted' => false, 'field_type' => 'text'];

            $value = $this->encryptionService->readFieldValue(
                $row['field_value'],
                $meta['is_encrypted']
            );

            if ($meta['field_type'] === 'address' && $value !== null) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $this->formatAddressForList($decoded);
                }
            }

            if (CustomFieldValidator::isFileType($meta['field_type']) && $value !== null) {
                $value = $this->formatFileForList($value, $meta['field_type']);
            }

            if (!isset($result[$memberId])) {
                $result[$memberId] = [];
            }
            $result[$memberId][$fieldId] = $value;
        }

        return $result;
    }

    /**
     * 주소를 목록 표시용 요약 문자열로 변환
     */
    private function formatAddressForList(array $address): string
    {
        $parts = [];

        if (!empty($address['address1'])) {
            $addr1 = $address['address1'];
            if (mb_strlen($addr1) > 30) {
                $addr1 = mb_substr($addr1, 0, 30) . '...';
            }
            $parts[] = $addr1;
        }

        return implode(' ', $parts) ?: '-';
    }

    /**
     * 파일/아바타 필드 값을 목록 표시용으로 변환
     *
     * public 디스크(아바타) → 만료 없는 /storage 직링크, secure(첨부) → HMAC 다운로드 토큰.
     * 아바타는 뷰에서 썸네일로, 파일은 다운로드 링크로 렌더.
     *
     * @return string|array URL 포함 배열 또는 빈 문자열
     */
    private function formatFileForList(?string $value, string $fieldType = 'file'): string|array
    {
        if (empty($value)) {
            return '';
        }

        // parseMetaWithUrl: public=직링크, secure=토큰 URL 자동 분기
        $parsed = $this->secureFileService
            ? $this->secureFileService->parseMetaWithUrl($value)
            : CustomFieldFileHandler::parseFileMeta($value);

        if (!$parsed || empty($parsed['url'])) {
            return '';
        }

        return [
            '_type' => $fieldType === 'avatar' ? 'avatar' : 'file',
            'filename' => $parsed['filename'] ?? '',
            'url' => $parsed['url'],
        ];
    }

    // =========================================================================
    // 회원 검색 (관리자 선택 등)
    // =========================================================================

    /**
     * 회원 검색 (아이디, 이름 기준)
     *
     * 게시판 그룹 관리자 선택 등에서 사용
     *
     * Note: members 테이블에는 user_id만 존재, name/email은 member_field_values에 있음
     *
     * @param int $domainId 도메인 ID
     * @param string $keyword 검색어 (아이디)
     * @param int $limit 최대 결과 수
     * @return Result
     */
    public function searchMembers(int $domainId, string $keyword, int $limit = 10): Result
    {
        if (empty($keyword) || mb_strlen($keyword) < 2) {
            return Result::failure('검색어를 2글자 이상 입력해주세요.', ['members' => []]);
        }

        $members = $this->memberRepository->searchByKeyword($domainId, $keyword, $limit);

        $result = [];
        foreach ($members as $member) {
            $result[] = [
                'member_id' => $member->getMemberId(),
                'user_id' => $member->getUserId(),
                'nickname' => $member->getNickname(),
                'level_value' => $member->getLevelValue(),
            ];
        }

        return Result::success('', ['members' => $result]);
    }

    // =========================================================================
    // 이벤트 발행 헬퍼
    // =========================================================================

    /**
     * 이벤트 발행
     *
     * EventDispatcher가 없으면 이벤트를 그대로 반환합니다.
     * 호출부에서 직접 new Event()로 생성하여 전달하는 패턴 사용:
     *
     * ```php
     * $this->dispatch(new MemberRegisteredByAdminEvent($member, $adminId));
     * $this->dispatch(new MemberUpdatedByAdminEvent($member, $changes, $adminId));
     * ```
     *
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }
}
