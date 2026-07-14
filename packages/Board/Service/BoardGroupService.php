<?php
namespace Mublo\Packages\Board\Service;

use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardPermissionRepository;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Entity\BoardPermission;
use Mublo\Packages\Board\Enum\PermissionTargetType;
use Mublo\Core\Result\Result;
use Mublo\Helper\Form\FormHelper;

/**
 * BoardGroup Service
 *
 * 게시판 그룹 비즈니스 로직 담당
 *
 * 책임:
 * - 그룹 CRUD 비즈니스 로직
 * - 유효성 검증
 * - 정렬 순서 관리
 * - 그룹 관리자 관리 (BoardPermissionRepository 위임)
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class BoardGroupService
{
    private BoardGroupRepository $repository;
    private BoardPermissionRepository $permissionRepository;

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
        'board',
        'community',
        'search',
        'install',
        'setup',
    ];

    public function __construct(
        BoardGroupRepository $repository,
        BoardPermissionRepository $permissionRepository
    ) {
        $this->repository = $repository;
        $this->permissionRepository = $permissionRepository;
    }

    /**
     * 도메인별 그룹 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardGroup[]
     */
    public function getGroups(int $domainId): array
    {
        return $this->repository->findByDomain($domainId);
    }

    /**
     * 도메인별 그룹 목록 조회 (게시판 수 포함)
     *
     * @param int $domainId 도메인 ID
     * @return array 그룹 배열 (board_count 포함)
     */
    public function getGroupsWithCount(int $domainId): array
    {
        $groups = $this->repository->findByDomain($domainId);
        $result = [];

        foreach ($groups as $group) {
            $data = $group->toArray();
            $data['board_count'] = $this->repository->getBoardCount($group->getGroupId());
            $result[] = $data;
        }

        return $result;
    }

    /**
     * 그룹에 속한 게시판 수 조회
     *
     * @param int $groupId 그룹 ID
     * @return int
     */
    public function getBoardCount(int $groupId): int
    {
        return $this->repository->getBoardCount($groupId);
    }

    /**
     * 도메인별 활성 그룹 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardGroup[]
     */
    public function getActiveGroups(int $domainId): array
    {
        return $this->repository->findActiveByDomain($domainId);
    }

    /**
     * 단일 그룹 조회
     */
    public function getGroup(int $groupId): ?BoardGroup
    {
        return $this->repository->find($groupId);
    }

    /**
     * 슬러그로 그룹 조회
     */
    public function getGroupBySlug(int $domainId, string $slug): ?BoardGroup
    {
        return $this->repository->findBySlug($domainId, $slug);
    }

    /**
     * 그룹 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 그룹 데이터
     * @return Result
     */
    public function createGroup(int $domainId, array $data): Result
    {
        // 필수 필드 검증
        if (empty($data['group_slug']) || empty($data['group_name'])) {
            return Result::failure('필수 필드가 누락되었습니다. (슬러그, 그룹명)');
        }

        $slug = $data['group_slug'];

        // 슬러그 유효성 검증
        $slugValidation = $this->validateSlug($slug);
        if ($slugValidation->isFailure()) {
            return $slugValidation;
        }

        // 슬러그 중복 검사
        if ($this->repository->existsBySlug($domainId, $slug)) {
            return Result::failure('이미 사용중인 슬러그입니다.');
        }

        // 다음 정렬 순서
        $sortOrder = $this->repository->getNextSortOrder($domainId);

        // 데이터 정규화
        $insertData = $this->normalizeData($data);
        $insertData['domain_id'] = $domainId;
        $insertData['sort_order'] = $sortOrder;

        // 생성
        $groupId = $this->repository->create($insertData);

        if ($groupId) {
            return Result::success('그룹이 생성되었습니다.', ['group_id' => $groupId]);
        }

        return Result::failure('그룹 생성에 실패했습니다.');
    }

    /**
     * 그룹 수정
     *
     * @param int $groupId 그룹 ID
     * @param array $data 수정 데이터
     * @return Result
     */
    public function updateGroup(int $groupId, array $data): Result
    {
        $group = $this->repository->find($groupId);

        if (!$group) {
            return Result::failure('그룹을 찾을 수 없습니다.');
        }

        // 슬러그 변경 시 유효성 및 중복 검사
        if (!empty($data['group_slug']) && $data['group_slug'] !== $group->getGroupSlug()) {
            $slugValidation = $this->validateSlug($data['group_slug']);
            if ($slugValidation->isFailure()) {
                return $slugValidation;
            }

            if ($this->repository->existsBySlugExceptSelf($group->getDomainId(), $data['group_slug'], $groupId)) {
                return Result::failure('이미 사용중인 슬러그입니다.');
            }
        }

        // 데이터 정규화
        $updateData = $this->normalizeData($data);

        // domain_id, sort_order는 수정 불가
        unset($updateData['domain_id'], $updateData['sort_order']);

        // 수정
        $affected = $this->repository->update($groupId, $updateData);

        if ($affected >= 0) {
            return Result::success('그룹이 수정되었습니다.');
        }

        return Result::failure('그룹 수정에 실패했습니다.');
    }

    /**
     * 그룹 삭제
     *
     * @param int $groupId 그룹 ID
     * @return Result
     */
    public function deleteGroup(int $groupId): Result
    {
        $group = $this->repository->find($groupId);

        if (!$group) {
            return Result::failure('그룹을 찾을 수 없습니다.');
        }

        // 그룹에 속한 게시판이 있는지 확인
        $boardCount = $this->repository->getBoardCount($groupId);
        if ($boardCount > 0) {
            return Result::failure("그룹에 속한 게시판({$boardCount}개)이 있어 삭제할 수 없습니다.");
        }

        // 그룹 관련 권한 삭제
        $this->deleteGroupPermissions($groupId);

        // 삭제
        $affected = $this->repository->delete($groupId);

        if ($affected > 0) {
            return Result::success('그룹이 삭제되었습니다.');
        }

        return Result::failure('그룹 삭제에 실패했습니다.');
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param int[] $groupIds 정렬된 그룹 ID 배열
     * @return Result
     */
    public function updateOrder(int $domainId, array $groupIds): Result
    {
        if (empty($groupIds)) {
            return Result::failure('정렬할 그룹 목록이 비어있습니다.');
        }

        $result = $this->repository->updateOrder($groupIds);

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
     * @param int|null $excludeId 제외할 그룹 ID (수정 시)
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
     * @param array $items [groupId => [field => value, ...], ...]
     * @return Result
     */
    public function batchUpdate(array $items): Result
    {
        if (empty($items)) {
            return Result::failure('수정할 항목이 없습니다.', ['updated' => 0]);
        }

        $updated = 0;
        foreach ($items as $groupId => $data) {
            $groupId = (int) $groupId;
            $group = $this->repository->find($groupId);

            if ($group) {
                $normalizedData = $this->normalizeData($data);
                // domain_id, sort_order, group_slug는 일괄 수정에서 제외
                unset($normalizedData['domain_id'], $normalizedData['sort_order'], $normalizedData['group_slug']);

                if (!empty($normalizedData)) {
                    $this->repository->update($groupId, $normalizedData);
                    $updated++;
                }
            }
        }

        if ($updated > 0) {
            return Result::success("{$updated}개 항목이 수정되었습니다.", ['updated' => $updated]);
        }

        return Result::failure('수정된 항목이 없습니다.', ['updated' => 0]);
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
            'numeric' => ['list_level', 'read_level', 'write_level', 'comment_level', 'download_level', 'sort_order'],
            'bool' => ['is_active'],
        ];

        // 서비스 계층은 부분 데이터 호출을 허용한다 — 입력에 존재하는 bool 만 강제.
        // "빈 체크박스 = 해제(0)" 채움은 관리자 컨트롤러 경계에서 이미 끝났다.
        $schema['bool'] = array_values(array_intersect($schema['bool'], array_keys($data)));

        $normalized = FormHelper::normalizeFormData($data, $schema);

        // 문자열 필드 (빈 문자열은 null로 처리)
        $stringFields = ['group_slug', 'group_name', 'group_description'];
        foreach ($stringFields as $field) {
            if (isset($data[$field])) {
                $value = trim($data[$field]);
                $normalized[$field] = ($value === '') ? null : $value;
            }
        }

        // 도메인 특화 후처리: 슬러그는 소문자로 변환
        if (isset($normalized['group_slug'])) {
            $normalized['group_slug'] = strtolower($normalized['group_slug']);
        }

        // Note: group_admin_ids는 별도 매핑 테이블로 이동 (BoardGroupAdminRepository)

        return $normalized;
    }

    // ========================================
    // 그룹 관리자 관련 메서드 (BoardPermissionRepository 위임)
    // ========================================

    /**
     * 특정 회원이 그룹 관리자인지 확인
     */
    public function isGroupAdmin(int $groupId, int $memberId): bool
    {
        return $this->permissionRepository->isGroupAdmin($groupId, $memberId);
    }

    /**
     * 그룹의 관리자 목록 조회
     *
     * @return int[] 관리자 member_id 배열
     */
    public function getGroupAdmins(int $groupId): array
    {
        return $this->permissionRepository->getAdminMemberIds(PermissionTargetType::GROUP->value, $groupId);
    }

    /**
     * 특정 회원이 관리하는 그룹 목록 조회
     *
     * @return int[] 그룹 group_id 배열
     */
    public function getGroupsByAdmin(int $memberId): array
    {
        $permissions = $this->permissionRepository->findByMember($memberId, PermissionTargetType::GROUP->value);

        return array_map(
            fn(BoardPermission $p) => $p->getTargetId(),
            array_filter($permissions, fn(BoardPermission $p) => $p->isAdmin())
        );
    }

    /**
     * 그룹 관리자 추가
     *
     * @param int $domainId 도메인 ID
     * @return Result
     */
    public function addGroupAdmin(int $domainId, int $groupId, int $memberId): Result
    {
        $group = $this->repository->find($groupId);
        if (!$group) {
            return Result::failure('그룹을 찾을 수 없습니다.');
        }

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
     *
     * @return Result
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
     * 그룹 관리자 동기화 (기존 모두 삭제 후 새로 설정)
     *
     * @param int $domainId 도메인 ID
     * @param int[] $memberIds 관리자 ID 배열
     * @return Result
     */
    public function syncGroupAdmins(int $domainId, int $groupId, array $memberIds): Result
    {
        $group = $this->repository->find($groupId);
        if (!$group) {
            return Result::failure('그룹을 찾을 수 없습니다.');
        }

        $this->permissionRepository->setAdmins(
            $domainId,
            PermissionTargetType::GROUP->value,
            $groupId,
            $memberIds
        );

        return Result::success('그룹 관리자가 설정되었습니다.');
    }

    /**
     * 그룹 삭제 시 관련 권한 모두 삭제
     */
    public function deleteGroupPermissions(int $groupId): int
    {
        return $this->permissionRepository->revokeAllByTarget(PermissionTargetType::GROUP->value, $groupId);
    }
}
