<?php
namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockPageQueryInterface;
use Mublo\Repository\Block\BlockPageRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Entity\Block\BlockPage;
use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Core\Event\Block\BlockPageCreatedEvent;
use Mublo\Core\Event\Block\BlockPageDeletedEvent;
use Mublo\Core\Event\Block\BlockPageUpdatedEvent;
use Mublo\Helper\Form\FormHelper;

/**
 * BlockPage Service
 *
 * 블록 페이지 비즈니스 로직 담당
 *
 * 책임:
 * - 페이지 CRUD 비즈니스 로직
 * - 유효성 검증
 * - BlockPageQueryInterface(안정 API) 구현 — 확장의 읽기 전용 통로
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class BlockPageService implements BlockPageQueryInterface
{
    private BlockPageRepository $repository;
    private BlockRowRepository $rowRepository;
    private ?EventDispatcher $eventDispatcher;

    /**
     * 페이지 코드 예약어 목록
     */
    private const RESERVED_CODES = [
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
        'board',
        'p',
    ];

    public function __construct(
        BlockPageRepository $repository,
        BlockRowRepository $rowRepository,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->repository = $repository;
        $this->rowRepository = $rowRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    /**
     * 도메인별 페이지 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BlockPage[]
     */
    public function getPages(int $domainId): array
    {
        return $this->repository->findByDomain($domainId);
    }

    /**
     * 도메인별 활성 페이지 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BlockPage[]
     */
    public function getActivePages(int $domainId): array
    {
        return $this->repository->findActiveByDomain($domainId);
    }

    /**
     * 단일 페이지 조회
     *
     * @param int|null $domainId 지정 시 해당 도메인 소유 페이지만 반환 (멀티도메인 IDOR 방지)
     */
    public function getPage(int $pageId, ?int $domainId = null): ?BlockPage
    {
        $page = $this->repository->find($pageId);

        if ($page && $domainId !== null && $page->getDomainId() !== $domainId) {
            return null;
        }

        return $page;
    }

    /**
     * 코드로 페이지 조회
     */
    public function getPageByCode(int $domainId, string $code): ?BlockPage
    {
        return $this->repository->findByCode($domainId, $code);
    }

    /**
     * 활성 블록 페이지를 코드로 조회 (BlockPageQueryInterface)
     *
     * findByCode 가 is_deleted=0 만 반환하므로 그대로 위임한다.
     */
    public function findActiveByCode(int $domainId, string $pageCode): ?BlockPage
    {
        return $this->repository->findByCode($domainId, $pageCode);
    }

    /**
     * 페이지 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 페이지 데이터
     * @return Result
     */
    public function createPage(int $domainId, array $data): Result
    {
        // 필수 필드 검증
        if (empty($data['page_code']) || empty($data['page_title'])) {
            return Result::failure('필수 필드가 누락되었습니다. (코드, 제목)');
        }

        $code = $data['page_code'];

        // 코드 유효성 검증
        $codeValidation = $this->validateCode($code);
        if (!$codeValidation['valid']) {
            return Result::failure($codeValidation['message']);
        }

        // 코드 중복 검사
        if ($this->repository->existsByCode($domainId, $code)) {
            return Result::failure('이미 사용중인 코드입니다.');
        }

        // 데이터 정규화
        $insertData = $this->normalizeData($data);
        $insertData['domain_id'] = $domainId;

        // 생성
        $pageId = $this->repository->create($insertData);

        if ($pageId) {
            $this->dispatch(new BlockPageCreatedEvent(
                $domainId,
                $pageId,
                $insertData['page_code'],
                $insertData['page_title']
            ));
            return Result::success('페이지가 생성되었습니다.', ['page_id' => $pageId]);
        }

        return Result::failure('페이지 생성에 실패했습니다.');
    }

    /**
     * 페이지 수정
     *
     * @param int $pageId 페이지 ID
     * @param array $data 수정 데이터
     * @return Result
     */
    public function updatePage(int $pageId, array $data, ?int $domainId = null): Result
    {
        $page = $this->repository->find($pageId);

        if (!$page || ($domainId !== null && $page->getDomainId() !== $domainId)) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        // 코드 변경 시 유효성 및 중복 검사
        if (!empty($data['page_code']) && $data['page_code'] !== $page->getPageCode()) {
            $codeValidation = $this->validateCode($data['page_code']);
            if (!$codeValidation['valid']) {
                return Result::failure($codeValidation['message']);
            }

            if ($this->repository->existsByCodeExceptSelf($page->getDomainId(), $data['page_code'], $pageId)) {
                return Result::failure('이미 사용중인 코드입니다.');
            }
        }

        // 데이터 정규화
        $updateData = $this->normalizeData($data);

        // domain_id는 수정 불가
        unset($updateData['domain_id']);

        // 수정
        $affected = $this->repository->update($pageId, $updateData);

        if ($affected >= 0) {
            $this->dispatch(new BlockPageUpdatedEvent(
                $page->getDomainId(),
                $pageId,
                $page->getPageCode(),
                (string) ($updateData['page_code'] ?? $page->getPageCode()),
                (string) ($updateData['page_title'] ?? $page->getPageTitle())
            ));
            return Result::success('페이지가 수정되었습니다.');
        }

        return Result::failure('페이지 수정에 실패했습니다.');
    }

    /**
     * 페이지 삭제
     *
     * @param int $pageId 페이지 ID
     * @return Result
     */
    public function deletePage(int $pageId, ?int $domainId = null): Result
    {
        $page = $this->repository->find($pageId);

        if (!$page || ($domainId !== null && $page->getDomainId() !== $domainId)) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        // 연결된 행이 있는지 확인
        $rowCount = $this->rowRepository->countByPage($pageId);
        if ($rowCount > 0) {
            return Result::failure("연결된 행({$rowCount}개)이 있어 삭제할 수 없습니다. 먼저 행을 삭제해주세요.");
        }

        // 삭제
        $domainId = $page->getDomainId();
        $pageCode = $page->getPageCode();
        $affected = $this->repository->delete($pageId);

        if ($affected > 0) {
            $this->dispatch(new BlockPageDeletedEvent($domainId, $pageCode));
            return Result::success('페이지가 삭제되었습니다.');
        }

        return Result::failure('페이지 삭제에 실패했습니다.');
    }

    /**
     * 페이지 소프트 삭제 (is_deleted = 1)
     *
     * 연결된 행/콘텐츠를 보존한 채 목록에서만 숨김
     */
    public function softDeletePage(int $pageId, int $domainId): Result
    {
        $page = $this->repository->find($pageId);

        if (!$page || $page->getDomainId() !== $domainId) {
            return Result::failure('페이지를 찾을 수 없습니다.');
        }

        $affected = $this->repository->update($pageId, ['is_deleted' => 1]);

        if ($affected > 0) {
            $this->dispatch(new BlockPageDeletedEvent($domainId, $page->getPageCode()));
            return Result::success('페이지가 삭제되었습니다.');
        }

        return Result::failure('페이지 삭제에 실패했습니다.');
    }

    /**
     * 선택 옵션 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array [['value' => id, 'label' => title], ...]
     */
    public function getSelectOptions(int $domainId): array
    {
        return $this->repository->getSelectOptions($domainId);
    }

    /**
     * 페이지네이션
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @param string $keyword 검색어 (코드/제목/설명 OR)
     * @return array
     */
    public function paginate(int $domainId, int $page = 1, int $perPage = 15, string $keyword = ''): array
    {
        return $this->repository->paginateByDomain($domainId, $page, $perPage, $keyword);
    }

    /**
     * 코드 사용 가능 여부 확인 (유효성 + 중복 검사)
     *
     * @param int $domainId 도메인 ID
     * @param string $code 코드
     * @param int|null $excludeId 제외할 페이지 ID (수정 시)
     * @return array ['available' => bool, 'message' => string]
     */
    public function checkCodeAvailability(int $domainId, string $code, ?int $excludeId = null): array
    {
        // 코드 유효성 검증
        $validation = $this->validateCode($code);
        if (!$validation['valid']) {
            return [
                'available' => false,
                'message' => $validation['message'],
            ];
        }

        // 중복 검사
        if ($excludeId !== null && $excludeId > 0) {
            $exists = $this->repository->existsByCodeExceptSelf($domainId, $code, $excludeId);
        } else {
            $exists = $this->repository->existsByCode($domainId, $code);
        }

        if ($exists) {
            return [
                'available' => false,
                'message' => '이미 사용중인 코드입니다.',
            ];
        }

        return [
            'available' => true,
            'message' => '사용 가능한 코드입니다.',
        ];
    }

    /**
     * 코드 유효성 검증
     *
     * @param string $code 코드
     * @return array ['valid' => bool, 'message' => ?string]
     */
    public function validateCode(string $code): array
    {
        // 빈 문자열 검사
        if (empty($code)) {
            return [
                'valid' => false,
                'message' => '코드를 입력해주세요.',
            ];
        }

        // 길이 검사 (2~50자)
        if (strlen($code) < 2 || strlen($code) > 50) {
            return [
                'valid' => false,
                'message' => '코드는 2~50자 사이로 입력해주세요.',
            ];
        }

        // 형식 검사 (영문 소문자, 숫자, 하이픈만 허용)
        if (!preg_match('/^[a-z0-9-]+$/', $code)) {
            return [
                'valid' => false,
                'message' => '코드는 영문 소문자, 숫자, 하이픈(-)만 사용할 수 있습니다.',
            ];
        }

        // 시작/끝 하이픈 검사
        if (str_starts_with($code, '-') || str_ends_with($code, '-')) {
            return [
                'valid' => false,
                'message' => '코드는 하이픈으로 시작하거나 끝날 수 없습니다.',
            ];
        }

        // 예약어 검사
        if (in_array($code, self::RESERVED_CODES, true)) {
            return [
                'valid' => false,
                'message' => '예약된 코드는 사용할 수 없습니다.',
            ];
        }

        return [
            'valid' => true,
            'message' => null,
        ];
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
            'numeric' => ['layout_type', 'allow_level', 'use_fullpage', 'custom_width', 'sidebar_left_width', 'sidebar_right_width'],
            'bool' => ['use_header', 'use_footer', 'is_active', 'sidebar_left_mobile', 'sidebar_right_mobile'],
        ];

        $normalized = FormHelper::normalizeFormData($data, $schema);

        // 문자열 필드 (빈 문자열은 null로 처리)
        $stringFields = ['page_code', 'page_title', 'page_description', 'seo_title', 'seo_description', 'seo_keywords'];
        foreach ($stringFields as $field) {
            if (isset($data[$field])) {
                $value = trim($data[$field]);
                $normalized[$field] = ($value === '') ? null : $value;
            }
        }

        // 도메인 특화 후처리: 코드는 소문자로 변환
        if (isset($normalized['page_code'])) {
            $normalized['page_code'] = strtolower($normalized['page_code']);
        }

        return $normalized;
    }
}
