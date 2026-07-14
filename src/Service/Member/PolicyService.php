<?php
namespace Mublo\Service\Member;

use Mublo\Entity\Member\Policy;
use Mublo\Contract\Member\PolicyDocument;
use Mublo\Contract\Member\PolicyQueryInterface;
use Mublo\Enum\Policy\PolicyType;
use Mublo\Repository\Member\PolicyRepository;
use Mublo\Repository\Member\PolicyRevisionRepository;
use Mublo\Core\Result\Result;
use Mublo\Helper\Form\FormHelper;

/**
 * PolicyService
 *
 * 정책/약관 관리 비즈니스 로직
 *
 * 책임:
 * - 정책 CRUD
 * - 정책 데이터 검증
 * - 슬러그 자동 생성
 */
class PolicyService implements PolicyQueryInterface
{
    private PolicyRepository $policyRepository;

    public function __construct(
        PolicyRepository $policyRepository,
        private readonly PolicyRevisionRepository $revisionRepository,
    )
    {
        $this->policyRepository = $policyRepository;
    }

    // =========================================================================
    // 조회
    // =========================================================================

    /**
     * 도메인별 전체 정책 목록 조회
     *
     * @return Policy[]
     */
    public function getAllByDomain(int $domainId): array
    {
        return $this->policyRepository->getAllByDomain($domainId);
    }

    /**
     * 도메인별 활성 정책 목록 조회
     *
     * @return Policy[]
     */
    public function getActiveByDomain(int $domainId): array
    {
        return $this->policyRepository->getActiveByDomain($domainId);
    }

    /**
     * 회원가입 시 출력할 정책 목록 조회
     *
     * @return Policy[]
     */
    public function getRegisterPolicies(int $domainId): array
    {
        return $this->policyRepository->getRegisterPolicies($domainId);
    }

    /**
     * 정책 상세 조회
     */
    public function findById(int $policyId): ?Policy
    {
        return $this->policyRepository->findById($policyId);
    }

    /**
     * 슬러그로 정책 조회
     */
    public function findBySlug(int $domainId, string $slug): ?Policy
    {
        return $this->policyRepository->findBySlug($domainId, $slug);
    }

    /**
     * 타입으로 정책 조회
     */
    public function findByType(int $domainId, string $policyType): ?Policy
    {
        return $this->policyRepository->findByType($domainId, $policyType);
    }

    /**
     * 회원가입 필수 정책 목록 조회
     *
     * @return Policy[]
     */
    public function getRequiredForSignup(int $domainId): array
    {
        return $this->policyRepository->getRequiredForSignup($domainId);
    }

    /**
     * 페이지네이션 목록 조회
     */
    public function getPaginatedList(int $domainId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->policyRepository->getPaginatedList($domainId, $page, $perPage, $filters);
    }

    /** @return list<PolicyDocument> */
    public function activeDocuments(int $domainId): array
    {
        return $this->documentsForPolicies($this->getActiveByDomain($domainId));
    }

    public function renderDocument(PolicyDocument $document, array $domainConfig): string
    {
        return $this->replaceVariables(
            $document->content,
            $domainConfig,
            Policy::fromArray([
                'policy_id' => $document->policyId,
                'domain_id' => $document->domainId,
                'title' => $document->title,
                'content' => $document->content,
                'version' => $document->version,
                'created_at' => $document->createdAt,
            ])
        );
    }

    /**
     * 가입 세션이 현재 활성 개정본과 정확히 일치하는지 최종 확인한다.
     *
     * @return Result data: ['agreements' => array<int, array<string, mixed>>]
     */
    public function validateSignupAgreements(int $domainId, array $submitted): Result
    {
        $policies = $this->getRegisterPolicies($domainId);
        $revisions = $this->revisionRepository->findCurrentForPolicies(array_map(
            static fn(Policy $policy): int => $policy->getPolicyId(),
            $policies
        ));
        $validated = [];

        foreach ($policies as $policy) {
            $policyId = $policy->getPolicyId();
            $revision = $revisions[$policyId] ?? null;
            if ($revision === null) {
                return Result::failure('약관 개정본을 확인할 수 없습니다. 관리자에게 문의해주세요.');
            }

            $agreement = $submitted[$policyId] ?? null;
            if ($agreement === null) {
                if ($policy->isRequired()) {
                    return Result::failure('"' . $policy->getPolicyTitle() . '" 에 다시 동의해주세요.');
                }
                continue;
            }

            if (!is_array($agreement)
                || (int) ($agreement['revision_id'] ?? 0) !== (int) $revision['revision_id']
                || !hash_equals((string) $revision['content_hash'], (string) ($agreement['content_hash'] ?? ''))) {
                return Result::failure('약관이 변경되었습니다. 최신 약관을 확인하고 다시 동의해주세요.');
            }

            $validated[$policyId] = $this->agreementFromRevision($revision);
        }

        return Result::success('', ['agreements' => $validated]);
    }

    /**
     * 체크박스로 선택한 현재 정책을 가입 세션용 불변 descriptor로 변환한다.
     */
    public function agreementSnapshot(int $domainId, array $selectedPolicyIds): Result
    {
        $selected = array_fill_keys(array_map('intval', $selectedPolicyIds), true);
        $policies = $this->getRegisterPolicies($domainId);
        $revisions = $this->revisionRepository->findCurrentForPolicies(array_map(
            static fn(Policy $policy): int => $policy->getPolicyId(),
            $policies
        ));
        $agreements = [];

        foreach ($policies as $policy) {
            $policyId = $policy->getPolicyId();
            if (!isset($selected[$policyId])) {
                if ($policy->isRequired()) {
                    return Result::failure('"' . $policy->getPolicyTitle() . '" 에 동의해주세요.');
                }
                continue;
            }

            $revision = $revisions[$policyId] ?? null;
            if ($revision === null) {
                return Result::failure('약관 개정본을 확인할 수 없습니다. 관리자에게 문의해주세요.');
            }
            $agreements[$policyId] = $this->agreementFromRevision($revision);
        }

        return Result::success('', ['agreements' => $agreements]);
    }

    // =========================================================================
    // 생성
    // =========================================================================

    /**
     * 정책 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 정책 데이터
     * @return Result
     */
    public function create(int $domainId, array $data): Result
    {
        // 검증
        $validation = $this->validateData($domainId, $data);
        if ($validation->isFailure()) {
            return $validation;
        }

        // 슬러그 자동 생성
        $slug = $this->generateSlug($data['slug'] ?? '', $data['title'] ?? '');

        // 슬러그 중복 확인
        if ($this->policyRepository->existsBySlug($domainId, $slug)) {
            return Result::failure('이미 사용 중인 슬러그입니다.');
        }

        // terms, privacy 타입 중복 확인
        $policyType = $data['policy_type'] ?? PolicyType::CUSTOM->value;
        if (in_array($policyType, [PolicyType::TERMS->value, PolicyType::PRIVACY->value], true)) {
            if ($this->policyRepository->existsByType($domainId, $policyType)) {
                $typeLabel = PolicyType::options()[$policyType] ?? $policyType;
                return Result::failure("{$typeLabel}은(는) 이미 등록되어 있습니다.");
            }
        }

        // 데이터 정규화
        $createData = $this->normalizeData($data);
        $createData['domain_id'] = $domainId;
        $createData['slug'] = $slug;

        // 정렬 순서 자동 할당
        if (!isset($createData['sort_order']) || $createData['sort_order'] === 0) {
            $createData['sort_order'] = $this->policyRepository->getNextSortOrder($domainId);
        }

        try {
            $policyId = $this->policyRepository->getDb()->transaction(function () use ($createData): int {
                $policyId = (int) $this->policyRepository->create($createData);
                if ($policyId <= 0) {
                    throw new \RuntimeException('정책 생성 실패');
                }
                $revisionId = $this->createRevision($policyId, (int) $createData['domain_id'], $createData);
                $this->policyRepository->update($policyId, ['current_revision_id' => $revisionId]);
                return $policyId;
            });
        } catch (\Throwable $e) {
            return Result::failure('정책 생성에 실패했습니다.');
        }

        return Result::success('정책이 생성되었습니다.', ['policy_id' => $policyId]);
    }

    // =========================================================================
    // 수정
    // =========================================================================

    /**
     * 정책 수정
     *
     * @param int $policyId 정책 ID
     * @param array $data 수정 데이터
     * @return Result
     */
    public function update(int $policyId, array $data): Result
    {
        $policy = $this->policyRepository->findById($policyId);

        if (!$policy) {
            return Result::failure('정책을 찾을 수 없습니다.');
        }

        $domainId = $policy->getDomainId();

        // 검증
        $validation = $this->validateData($domainId, $data, $policyId);
        if ($validation->isFailure()) {
            return $validation;
        }

        // 슬러그 변경 시 중복 확인
        if (isset($data['slug'])) {
            $newSlug = $this->generateSlug($data['slug'], $data['title'] ?? $policy->getPolicyTitle());
            if ($newSlug !== $policy->getSlug()) {
                if ($this->policyRepository->existsBySlugExcept($domainId, $newSlug, $policyId)) {
                    return Result::failure('이미 사용 중인 슬러그입니다.');
                }
                $data['slug'] = $newSlug;
            }
        }

        // 정책타입 변경 시 중복 확인 (terms, privacy만)
        if (isset($data['policy_type']) && $data['policy_type'] !== $policy->getPolicyType()) {
            $newType = $data['policy_type'];
            if (in_array($newType, [PolicyType::TERMS->value, PolicyType::PRIVACY->value], true)) {
                if ($this->policyRepository->existsByTypeExcept($domainId, $newType, $policyId)) {
                    $typeLabel = PolicyType::options()[$newType] ?? $newType;
                    return Result::failure("{$typeLabel}은(는) 이미 등록되어 있습니다.");
                }
            }
        }

        $newTitle = (string) ($data['title'] ?? $policy->getPolicyTitle());
        $newContent = (string) ($data['content'] ?? $policy->getPolicyContent());
        $newVersion = (string) ($data['version'] ?? $policy->getPolicyVersion());
        $documentChanged = $newTitle !== $policy->getPolicyTitle()
            || $newContent !== $policy->getPolicyContent();
        if ($documentChanged && $newVersion === $policy->getPolicyVersion()) {
            return Result::failure('정책 제목이나 내용을 변경할 때는 버전을 올려주세요.');
        }
        if ($newVersion !== $policy->getPolicyVersion()
            && !version_compare($newVersion, $policy->getPolicyVersion(), '>')) {
            return Result::failure('정책 버전은 현재 버전보다 높아야 합니다.');
        }

        // 데이터 정규화
        $updateData = $this->normalizeData($data);

        try {
            $this->policyRepository->getDb()->transaction(function () use (
                $policyId,
                $domainId,
                $policy,
                $updateData,
                $newTitle,
                $newContent,
                $newVersion,
                $documentChanged
            ): void {
                $revisionChanged = $documentChanged || $newVersion !== $policy->getPolicyVersion();
                $this->policyRepository->update($policyId, $updateData);
                if ($revisionChanged) {
                    $revisionId = $this->createRevision($policyId, $domainId, [
                        'title' => $newTitle,
                        'content' => $newContent,
                        'version' => $newVersion,
                    ]);
                    $this->policyRepository->update($policyId, ['current_revision_id' => $revisionId]);
                }
            });
        } catch (\Throwable $e) {
            return Result::failure('정책 수정에 실패했습니다. 버전이 중복되지 않았는지 확인해주세요.');
        }

        return Result::success('정책이 수정되었습니다.');
    }

    /**
     * 단일 필드 업데이트 (목록에서 인라인 수정용)
     *
     * @param int $policyId 정책 ID
     * @param string $field 필드명
     * @param mixed $value 값
     * @return Result
     */
    public function updateField(int $policyId, string $field, mixed $value): Result
    {
        $policy = $this->policyRepository->findById($policyId);

        if (!$policy) {
            return Result::failure('정책을 찾을 수 없습니다.');
        }

        // 허용된 필드만 수정 가능
        $allowedFields = ['is_active', 'is_required', 'show_in_register', 'sort_order'];
        if (!in_array($field, $allowedFields, true)) {
            return Result::failure('수정할 수 없는 필드입니다.');
        }

        // 타입 변환
        if (in_array($field, ['is_active', 'is_required', 'show_in_register'], true)) {
            $value = (int) (bool) $value;
        } elseif ($field === 'sort_order') {
            $value = (int) $value;
        }

        // 업데이트 실행
        $this->policyRepository->update($policyId, [$field => $value]);

        return Result::success('수정되었습니다.');
    }

    // =========================================================================
    // 삭제
    // =========================================================================

    /**
     * 정책 삭제
     *
     * @param int $policyId 정책 ID
     * @return Result
     */
    public function delete(int $policyId): Result
    {
        $policy = $this->policyRepository->findById($policyId);

        if (!$policy) {
            return Result::failure('정책을 찾을 수 없습니다.');
        }

        // 필수 약관(이용약관, 개인정보처리방침)은 삭제 경고
        if ($policy->isEssentialType() && $policy->isActive()) {
            return Result::failure('이용약관 및 개인정보처리방침은 비활성화 후 삭제해주세요.');
        }

        // 동의 증거와 개정본을 보존하기 위해 물리 삭제 대신 보관 처리한다.
        $this->policyRepository->update($policyId, [
            'is_active' => 0,
            'show_in_register' => 0,
        ]);

        return Result::success('정책이 보관되었습니다.');
    }

    // =========================================================================
    // 정렬
    // =========================================================================

    /**
     * 정렬 순서 일괄 업데이트
     *
     * @param array $orderData [policy_id => sort_order, ...]
     * @return Result
     */
    public function updateSortOrders(array $orderData): Result
    {
        if (empty($orderData)) {
            return Result::failure('정렬 데이터가 없습니다.');
        }

        try {
            $this->policyRepository->getDb()->transaction(function () use ($orderData) {
                $this->policyRepository->updateSortOrders($orderData);
            });

            return Result::success('정렬 순서가 저장되었습니다.');
        } catch (\Throwable $e) {
            return Result::failure('정렬 순서 저장에 실패했습니다.');
        }
    }

    // =========================================================================
    // 검증
    // =========================================================================

    /**
     * 정책 데이터 검증
     *
     * @param int $domainId 도메인 ID
     * @param array $data 검증할 데이터
     * @param int|null $excludeId 수정 시 제외할 ID
     * @return Result
     */
    private function validateData(int $domainId, array $data, ?int $excludeId = null): Result
    {
        // 제목 필수
        if (empty($data['title'])) {
            return Result::failure('정책 제목을 입력해주세요.');
        }

        // 제목 길이 (최대 200자)
        if (mb_strlen($data['title']) > 200) {
            return Result::failure('정책 제목은 200자 이내로 입력해주세요.');
        }

        // 내용 필수
        if (empty($data['content'])) {
            return Result::failure('정책 내용을 입력해주세요.');
        }

        // 정책 타입 유효성
        if (!empty($data['policy_type']) && !array_key_exists($data['policy_type'], PolicyType::options())) {
            return Result::failure('유효하지 않은 정책 타입입니다.');
        }

        // 슬러그 형식 검증 (영문, 숫자, 하이픈만)
        if (!empty($data['slug']) && !preg_match('/^[a-z0-9\-]+$/', $data['slug'])) {
            return Result::failure('슬러그는 영문 소문자, 숫자, 하이픈(-)만 사용 가능합니다.');
        }

        // 슬러그 길이
        if (!empty($data['slug']) && mb_strlen($data['slug']) > 50) {
            return Result::failure('슬러그는 50자 이내로 입력해주세요.');
        }

        // 버전 형식 검증 (간단한 형식)
        if (!empty($data['version']) && !preg_match('/^[\d\.]+$/', $data['version'])) {
            return Result::failure('버전은 숫자와 점(.)만 사용 가능합니다.');
        }

        return Result::success();
    }

    /**
     * 정책 데이터 정규화
     *
     * FormHelper::normalizeFormData() 활용
     */
    private function normalizeData(array $data): array
    {
        // FormHelper 스키마 정의
        $schema = [
            'numeric' => ['sort_order'],
            'bool' => ['is_required', 'is_active', 'show_in_register'],
            'required_string' => ['title'],
            'enum' => [
                'policy_type' => [
                    'values' => array_keys(PolicyType::options()),
                    'default' => PolicyType::CUSTOM->value,
                ],
            ],
        ];

        $normalized = FormHelper::normalizeFormData($data, $schema);

        // 도메인 특화 후처리
        // 슬러그 소문자 변환
        if (isset($normalized['slug'])) {
            $normalized['slug'] = strtolower($normalized['slug']);
        }

        // 버전 기본값
        if (isset($normalized['version']) && empty($normalized['version'])) {
            $normalized['version'] = '1.0';
        }

        // content는 HTML 허용 (sanitize 하지 않음)
        if (isset($data['content'])) {
            $normalized['content'] = $data['content'];
        }

        return $normalized;
    }

    /**
     * 슬러그 생성
     *
     * @param string $slug 입력된 슬러그
     * @param string $title 제목 (슬러그가 없을 때 사용)
     * @return string
     */
    private function generateSlug(string $slug, string $title): string
    {
        // 슬러그가 있으면 정리해서 반환
        if (!empty($slug)) {
            return strtolower(preg_replace('/[^a-z0-9\-]/i', '-', trim($slug)));
        }

        // 제목으로 슬러그 생성 (한글 제거, 영문/숫자만)
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9\s\-]/i', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        // 슬러그가 비어있으면 랜덤 생성
        if (empty($slug)) {
            $slug = 'policy-' . substr(md5(uniqid()), 0, 8);
        }

        return $slug;
    }

    // =========================================================================
    // 기본 약관 시딩
    // =========================================================================

    /**
     * 새 도메인 생성 시 기본 약관 등록
     *
     * 이용약관 + 개인정보처리방침을 기본 등록합니다.
     */
    public function seedDefaultPolicies(int $domainId): Result
    {
        $defaults = $this->getDefaultPolicyDefinitions();
        $created = 0;

        foreach ($defaults as $def) {
            // 이미 등록된 타입이면 건너뛰기
            if ($this->policyRepository->existsByType($domainId, $def['policy_type'])) {
                continue;
            }

            $data = [
                'domain_id'      => $domainId,
                'slug'           => $def['slug'],
                'policy_type'    => $def['policy_type'],
                'title'          => $def['title'],
                'content'        => $def['content'],
                'version'        => '1.0',
                'is_required'    => $def['is_required'],
                'is_active'      => 1,
                'sort_order'     => $def['sort_order'],
                'show_in_register' => 1,
            ];

            $result = $this->create($domainId, $data);
            if ($result->isFailure()) {
                return Result::failure("기본 약관 생성 실패: {$def['title']}");
            }
            $created++;
        }

        return Result::success("기본 약관 {$created}건이 등록되었습니다.");
    }

    /**
     * 기본 약관 정의
     */
    private function getDefaultPolicyDefinitions(): array
    {
        return [
            [
                'slug'        => 'terms',
                'policy_type' => PolicyType::TERMS->value,
                'title'       => '이용약관',
                'content'     => '<h2>이용약관</h2>' . "\n"
                    . '<p>본 약관은 회사가 제공하는 서비스의 이용과 관련하여 회사와 이용자 간의 권리, 의무 및 책임사항 등을 규정합니다.</p>' . "\n"
                    . '<p>관리자 페이지에서 약관 내용을 수정해주세요.</p>',
                'is_required' => 1,
                'sort_order'  => 1,
            ],
            [
                'slug'        => 'privacy',
                'policy_type' => PolicyType::PRIVACY->value,
                'title'       => '개인정보처리방침',
                'content'     => '<h2>개인정보처리방침</h2>' . "\n"
                    . '<p>{#회사명}(이하 "회사")은 이용자의 개인정보를 중요시하며, 「개인정보 보호법」 등 관련 법령을 준수합니다.</p>' . "\n"
                    . '<p>관리자 페이지에서 약관 내용을 수정해주세요.</p>',
                'is_required' => 1,
                'sort_order'  => 2,
            ],
        ];
    }

    // =========================================================================
    // 유틸리티
    // =========================================================================

    /**
     * 정책 타입 옵션 반환
     *
     * @return array [type => label, ...]
     */
    public function getPolicyTypeOptions(): array
    {
        return PolicyType::options();
    }

    // =========================================================================
    // 치환 변수
    // =========================================================================

    /**
     * 정책 내용의 치환 변수를 실제 값으로 변환
     *
     * 지원 변수:
     * - {#회사명} : 회사/상호명
     * - {#홈페이지} : 홈페이지 URL
     * - {#대표자} : 대표자명
     * - {#책임자} : 개인정보 보호책임자
     * - {#전화번호} : 대표 전화번호
     * - {#이메일} : 대표 이메일
     * - {#사이트명} : 사이트명
     * - {#등록일자} : 약관 등록일
     * - {#적용일자} : 약관 시행일 (등록일과 동일)
     *
     * @param string $content 정책 내용 (치환 변수 포함)
     * @param array $domainConfig 도메인 설정 (domain_configs 테이블 row)
     * @param Policy|null $policy 정책 엔티티 (날짜용)
     * @return string 치환된 내용
     */
    public function replaceVariables(string $content, array $domainConfig, ?Policy $policy = null): string
    {
        // JSON 필드 파싱
        $companyConfig = $domainConfig['company_config'] ?? [];
        if (is_string($companyConfig)) {
            $companyConfig = json_decode($companyConfig, true) ?? [];
        }

        $siteConfig = $domainConfig['site_config'] ?? [];
        if (is_string($siteConfig)) {
            $siteConfig = json_decode($siteConfig, true) ?? [];
        }

        // 도메인 URL 생성
        $domain = $domainConfig['domain'] ?? '';
        $homepageUrl = $domain ? 'https://' . $domain : '';

        // 날짜 포맷 (적용일자는 등록일자와 동일하게 처리)
        $createdAt = $policy?->getCreatedAt() ?? date('Y-m-d');
        $effectiveDate = $createdAt;

        // 치환 맵
        $replacements = [
            '{#회사명}' => $companyConfig['name'] ?? '',
            '{#홈페이지}' => $homepageUrl,
            '{#대표자}' => $companyConfig['owner'] ?? '',
            '{#책임자}' => $companyConfig['privacy_officer'] ?? '',
            '{#전화번호}' => $companyConfig['tel'] ?? '',
            '{#이메일}' => $companyConfig['email'] ?? '',
            '{#사이트명}' => $siteConfig['site_title'] ?? '',
            '{#등록일자}' => $createdAt,
            '{#적용일자}' => $effectiveDate,
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }

    /**
     * 정책 내용을 치환 변수 적용 후 반환
     *
     * findById + replaceVariables 조합 헬퍼
     *
     * @param int $policyId 정책 ID
     * @param array $domainConfig 도메인 설정
     * @return string|null 치환된 내용 또는 null
     */
    public function getRenderedContent(int $policyId, array $domainConfig): ?string
    {
        $policy = $this->findById($policyId);

        if (!$policy) {
            return null;
        }

        return $this->replaceVariables($policy->getPolicyContent(), $domainConfig, $policy);
    }

    /**
     * 슬러그로 정책 내용을 치환 변수 적용 후 반환
     *
     * @param int $domainId 도메인 ID
     * @param string $slug 슬러그
     * @param array $domainConfig 도메인 설정
     * @return string|null 치환된 내용 또는 null
     */
    public function getRenderedContentBySlug(int $domainId, string $slug, array $domainConfig): ?string
    {
        $policy = $this->findBySlug($domainId, $slug);

        if (!$policy) {
            return null;
        }

        return $this->replaceVariables($policy->getPolicyContent(), $domainConfig, $policy);
    }

    /** @param list<Policy> $policies @return list<PolicyDocument> */
    private function documentsForPolicies(array $policies): array
    {
        $revisions = $this->revisionRepository->findCurrentForPolicies(array_map(
            static fn(Policy $policy): int => $policy->getPolicyId(),
            $policies
        ));
        $documents = [];

        foreach ($policies as $policy) {
            $revision = $revisions[$policy->getPolicyId()] ?? null;
            if ($revision === null) {
                continue;
            }
            $documents[] = new PolicyDocument(
                policyId: $policy->getPolicyId(),
                revisionId: (int) $revision['revision_id'],
                domainId: $policy->getDomainId(),
                version: (string) $revision['version'],
                title: (string) $revision['title'],
                content: (string) $revision['content'],
                contentHash: (string) $revision['content_hash'],
                required: $policy->isRequired(),
                active: $policy->isActive(),
                createdAt: (string) ($revision['published_at'] ?? $revision['created_at'] ?? ''),
            );
        }

        return $documents;
    }

    private function createRevision(int $policyId, int $domainId, array $data): int
    {
        $content = (string) ($data['content'] ?? '');
        return $this->revisionRepository->create([
            'policy_id' => $policyId,
            'domain_id' => $domainId,
            'version' => (string) ($data['version'] ?? '1.0'),
            'title' => (string) ($data['title'] ?? ''),
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{revision_id:int, version:string, content_hash:string} */
    private function agreementFromRevision(array $revision): array
    {
        return [
            'revision_id' => (int) $revision['revision_id'],
            'version' => (string) $revision['version'],
            'content_hash' => (string) $revision['content_hash'],
        ];
    }
}
