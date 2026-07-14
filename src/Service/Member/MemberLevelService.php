<?php
namespace Mublo\Service\Member;

use Mublo\Entity\Member\MemberLevel;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Core\Result\Result;

/**
 * MemberLevelService
 *
 * 회원 등급 관리 비즈니스 로직 (전역)
 *
 * 책임:
 * - 등급 CRUD
 * - 등급 데이터 검증
 * - 삭제 전 사용 여부 확인
 *
 * Note: 등급은 전역 테이블로, 슈퍼관리자만 관리 가능합니다.
 */
class MemberLevelService
{
    private const CACHE_KEY_OPTIONS = 'member_level_options';
    private const CACHE_TTL = 600; // 10분

    private MemberLevelRepository $levelRepository;
    private CacheInterface $cache;

    public function __construct(
        MemberLevelRepository $levelRepository,
        CacheInterface $cache
    ) {
        $this->levelRepository = $levelRepository;
        $this->cache = $cache;
    }

    /**
     * 레벨 옵션 캐시 무효화
     */
    private function clearLevelOptionsCache(): void
    {
        $this->cache->delete(self::CACHE_KEY_OPTIONS);
    }

    // =========================================================================
    // 조회
    // =========================================================================

    /**
     * 전체 등급 목록 조회
     *
     * @return MemberLevel[]
     */
    public function getAll(): array
    {
        return $this->levelRepository->getAll();
    }

    /**
     * 등급 상세 조회
     */
    public function findById(int $levelId): ?MemberLevel
    {
        return $this->levelRepository->findById($levelId);
    }

    /**
     * 레벨값으로 등급 조회
     */
    public function findByValue(int $levelValue): ?MemberLevel
    {
        return $this->levelRepository->findByValue($levelValue);
    }

    /**
     * 페이지네이션 목록 조회
     */
    public function getPaginatedList(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->levelRepository->getPaginatedList($page, $perPage, $filters);
    }

    /**
     * 등급 옵션 조회 (select box용, 캐시 적용)
     *
     * @param bool $includeGuest 비회원(레벨 0) 포함 여부 (기본: true)
     * @param bool $excludeSuper 최고관리자 등급 제외 여부 (기본: false)
     * @return array [level_value => level_name, ...]
     */
    public function getOptionsForSelect(bool $includeGuest = true, bool $excludeSuper = false): array
    {
        $cache = $this->cache;
        $cached = $cache->get(self::CACHE_KEY_OPTIONS);

        if ($cached !== null) {
            $options = $cached;
        } else {
            $options = $this->levelRepository->getOptionsForSelect();

            // 비회원(레벨 0)이 없으면 기본 추가
            if (!isset($options[0])) {
                $options = [0 => '비회원'] + $options;
            }

            // 레벨값 기준 정렬 보장
            ksort($options);

            $cache->set(self::CACHE_KEY_OPTIONS, $options, self::CACHE_TTL);
        }

        if (!$includeGuest) {
            $options = array_filter($options, fn($k) => $k !== 0, ARRAY_FILTER_USE_KEY);
        }

        if ($excludeSuper) {
            $superLevels = $this->getSuperLevelValues();
            $options = array_filter($options, fn($k) => !in_array($k, $superLevels, true), ARRAY_FILTER_USE_KEY);
        }

        return $options;
    }

    /**
     * 최고관리자 레벨값 목록
     */
    private function getSuperLevelValues(): array
    {
        $levels = $this->levelRepository->getAll();
        $superValues = [];
        foreach ($levels as $level) {
            if ($level->isSuper()) {
                $superValues[] = $level->getLevelValue();
            }
        }
        return $superValues;
    }

    /**
     * 도메인 운영 가능 레벨 목록
     *
     * @return MemberLevel[]
     */
    public function getOperatorLevels(): array
    {
        return $this->levelRepository->getOperatorLevels();
    }

    /**
     * 관리자 모드 접근 가능 레벨 목록
     *
     * @return MemberLevel[]
     */
    public function getAdminLevels(): array
    {
        return $this->levelRepository->getAdminLevels();
    }

    // =========================================================================
    // 생성
    // =========================================================================

    /**
     * 등급 생성
     *
     * @param array $data 등급 데이터
     * @return Result
     */
    public function create(array $data): Result
    {
        // 검증
        $validation = $this->validateData($data);
        if ($validation->isFailure()) {
            return $validation;
        }

        // 레벨값 중복 확인
        $levelValue = (int) $data['level_value'];
        if ($this->levelRepository->existsByValue($levelValue)) {
            return Result::failure('이미 사용 중인 레벨값입니다.');
        }

        // 데이터 정규화
        $createData = $this->normalizeData($data);

        // 생성
        $levelId = $this->levelRepository->create($createData);

        if (!$levelId) {
            return Result::failure('등급 생성에 실패했습니다.');
        }

        // 캐시 무효화
        $this->clearLevelOptionsCache();

        return Result::success('등급이 생성되었습니다.', ['level_id' => $levelId]);
    }

    // =========================================================================
    // 수정
    // =========================================================================

    /**
     * 등급 수정
     *
     * @param int $levelId 등급 ID
     * @param array $data 수정 데이터
     * @return Result
     */
    public function update(int $levelId, array $data): Result
    {
        $level = $this->levelRepository->findById($levelId);

        if (!$level) {
            return Result::failure('등급 정보를 찾을 수 없습니다.');
        }

        // 슈퍼관리자 레벨 보호 (is_super=1인 레벨의 핵심 속성은 수정 제한)
        if ($level->isSuper()) {
            // is_super 플래그는 변경 불가
            if (isset($data['is_super']) && !(bool) $data['is_super']) {
                return Result::failure('최고관리자 등급의 슈퍼 권한은 해제할 수 없습니다.');
            }
            // 레벨값 변경 불가
            if (isset($data['level_value']) && (int) $data['level_value'] !== $level->getLevelValue()) {
                return Result::failure('최고관리자 등급의 레벨값은 변경할 수 없습니다.');
            }
        }

        // 검증
        $validation = $this->validateData($data, $levelId);
        if ($validation->isFailure()) {
            return $validation;
        }

        // 레벨값 변경 시 중복 확인
        if (isset($data['level_value'])) {
            $newLevelValue = (int) $data['level_value'];
            if ($newLevelValue !== $level->getLevelValue()) {
                if ($this->levelRepository->existsByValueExcept($newLevelValue, $levelId)) {
                    return Result::failure('이미 사용 중인 레벨값입니다.');
                }
            }
        }

        // 데이터 정규화
        $updateData = $this->normalizeData($data);

        // 수정
        $this->levelRepository->update($levelId, $updateData);

        // 캐시 무효화
        $this->clearLevelOptionsCache();

        return Result::success('등급이 수정되었습니다.');
    }

    // =========================================================================
    // 단일 필드 수정 (인라인 수정용)
    // =========================================================================

    /**
     * 단일 필드 업데이트 (목록에서 인라인 수정용)
     *
     * @param int $levelId 등급 ID
     * @param string $field 필드명
     * @param bool $value 값
     * @return Result
     */
    public function updateField(int $levelId, string $field, bool $value): Result
    {
        // 허용된 필드만 인라인 수정 가능
        $allowedFields = [
            'is_admin',
            'can_operate_domain',
        ];

        if (!in_array($field, $allowedFields, true)) {
            return Result::failure('수정할 수 없는 필드입니다.');
        }

        $level = $this->levelRepository->findById($levelId);

        if (!$level) {
            return Result::failure('등급 정보를 찾을 수 없습니다.');
        }

        // 슈퍼관리자 등급 보호
        if ($level->isSuper()) {
            return Result::failure('최고관리자 등급의 권한은 수정할 수 없습니다.');
        }

        // 업데이트 실행
        $this->levelRepository->update($levelId, [$field => (int) $value]);

        return Result::success('권한이 수정되었습니다.');
    }

    /**
     * 일괄 권한 수정
     *
     * @param array $levelIds 등급 ID 배열
     * @param array $permissions 권한 데이터 ['field' => bool, ...]
     * @return Result
     */
    public function bulkUpdatePermissions(array $levelIds, array $permissions): Result
    {
        if (empty($levelIds)) {
            return Result::failure('수정할 등급을 선택해주세요.');
        }

        // 허용된 권한 필드
        $allowedFields = [
            'is_admin',
            'can_operate_domain',
        ];

        // 유효한 권한만 필터링
        $updateData = [];
        foreach ($permissions as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $updateData[$field] = (int) (bool) $value;
            }
        }

        if (empty($updateData)) {
            return Result::failure('수정할 권한이 없습니다.');
        }

        try {
            $result = $this->levelRepository->getDb()->transaction(function () use ($levelIds, $updateData) {
                $updatedCount = 0;
                $skippedCount = 0;

                foreach ($levelIds as $levelId) {
                    $level = $this->levelRepository->findById((int) $levelId);

                    if (!$level) {
                        $skippedCount++;
                        continue;
                    }

                    // 슈퍼관리자 등급 보호
                    if ($level->isSuper()) {
                        $skippedCount++;
                        continue;
                    }

                    // 업데이트 실행
                    $this->levelRepository->update((int) $levelId, $updateData);
                    $updatedCount++;
                }

                return ['updated' => $updatedCount, 'skipped' => $skippedCount];
            });

            $updatedCount = $result['updated'];
            $skippedCount = $result['skipped'];

            if ($updatedCount === 0) {
                return Result::failure('수정된 등급이 없습니다. (최고관리자 등급은 수정할 수 없습니다.)');
            }

            $message = "{$updatedCount}개 등급의 권한이 수정되었습니다.";
            if ($skippedCount > 0) {
                $message .= " ({$skippedCount}개 건너뜀)";
            }

            return Result::success($message);
        } catch (\Throwable $e) {
            return Result::failure('일괄 권한 수정에 실패했습니다.');
        }
    }

    /**
     * 개별 등급 권한 수정 (검증 없이 권한 필드만 업데이트)
     *
     * @param int $levelId 등급 ID
     * @param array $permissions 권한 데이터 ['is_admin' => bool, ...]
     * @return Result
     */
    public function updatePermissions(int $levelId, array $permissions): Result
    {
        $level = $this->levelRepository->findById($levelId);

        if (!$level) {
            return Result::failure('등급 정보를 찾을 수 없습니다.');
        }

        // 슈퍼관리자 등급 보호
        if ($level->isSuper()) {
            return Result::failure('최고관리자 등급의 권한은 수정할 수 없습니다.');
        }

        // 허용된 권한 필드
        $allowedFields = [
            'is_admin',
            'can_operate_domain',
        ];

        // 유효한 권한만 필터링하여 업데이트
        $updateData = [];
        foreach ($permissions as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $updateData[$field] = (int) (bool) $value;
            }
        }

        if (empty($updateData)) {
            return Result::failure('수정할 권한이 없습니다.');
        }

        // 업데이트 실행
        $this->levelRepository->update($levelId, $updateData);

        return Result::success('권한이 수정되었습니다.');
    }

    // =========================================================================
    // 삭제
    // =========================================================================

    /**
     * 등급 삭제
     *
     * @param int $levelId 등급 ID
     * @return Result
     */
    public function delete(int $levelId): Result
    {
        $level = $this->levelRepository->findById($levelId);

        if (!$level) {
            return Result::failure('등급 정보를 찾을 수 없습니다.');
        }

        // 슈퍼관리자 등급 삭제 보호
        if ($level->isSuper()) {
            return Result::failure('최고관리자 등급은 삭제할 수 없습니다.');
        }

        // 사용 중인 회원 확인
        $memberCount = $this->levelRepository->countMembersUsingLevel($level->getLevelValue());
        if ($memberCount > 0) {
            return Result::failure("이 등급을 사용 중인 회원이 {$memberCount}명 있습니다. 회원의 등급을 변경 후 삭제해주세요.");
        }

        // 삭제
        $this->levelRepository->delete($levelId);

        // 캐시 무효화
        $this->clearLevelOptionsCache();

        return Result::success('등급이 삭제되었습니다.');
    }

    // =========================================================================
    // 검증
    // =========================================================================

    /**
     * 등급 데이터 검증
     *
     * @param array $data 검증할 데이터
     * @param int|null $excludeId 수정 시 제외할 ID
     * @return Result
     */
    private function validateData(array $data, ?int $excludeId = null): Result
    {
        // 레벨값 필수
        if (!isset($data['level_value']) || $data['level_value'] === '') {
            return Result::failure('레벨값을 입력해주세요.');
        }

        $levelValue = (int) $data['level_value'];

        // 레벨값 범위 (1~255)
        if ($levelValue < 1 || $levelValue > 255) {
            return Result::failure('레벨값은 1~255 사이여야 합니다.');
        }

        // 등급명 필수
        if (empty($data['level_name'])) {
            return Result::failure('등급명을 입력해주세요.');
        }

        // 등급명 길이 (최대 50자)
        if (mb_strlen($data['level_name']) > 50) {
            return Result::failure('등급명은 50자 이내로 입력해주세요.');
        }

        // 레벨 타입 유효성
        if (!empty($data['level_type']) && !array_key_exists($data['level_type'], MemberLevel::LEVEL_TYPES)) {
            return Result::failure('유효하지 않은 레벨 타입입니다.');
        }

        return Result::success();
    }

    /**
     * 등급 데이터 정규화
     */
    private function normalizeData(array $data): array
    {
        $normalized = [
            'level_value' => (int) $data['level_value'],
            'level_name' => trim($data['level_name']),
            'level_type' => $data['level_type'] ?? 'BASIC',
        ];

        // 역할 플래그
        $normalized['is_super'] = (int) (bool) ($data['is_super'] ?? false);
        $normalized['is_admin'] = (int) (bool) ($data['is_admin'] ?? false);
        $normalized['can_operate_domain'] = (int) (bool) ($data['can_operate_domain'] ?? false);

        return $normalized;
    }

    // =========================================================================
    // 유틸리티
    // =========================================================================

    /**
     * 레벨 타입 옵션 반환
     *
     * @return array [type => label, ...]
     */
    public function getLevelTypeOptions(): array
    {
        return MemberLevel::LEVEL_TYPES;
    }

    /**
     * 특정 레벨값을 사용하는 회원 수
     */
    public function countMembersUsingLevel(int $levelValue): int
    {
        return $this->levelRepository->countMembersUsingLevel($levelValue);
    }
}
