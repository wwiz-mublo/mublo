<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Enum\ShippingMethod;
use Mublo\Packages\Shop\Repository\ShippingRepository;

/**
 * ShippingService
 *
 * 배송 템플릿 비즈니스 로직
 *
 * 책임:
 * - 배송 템플릿 CRUD
 * - 택배사 목록 조회
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class ShippingService
{
    private ShippingRepository $shippingRepository;

    public function __construct(
        ShippingRepository $shippingRepository
    ) {
        $this->shippingRepository = $shippingRepository;
    }

    /**
     * 배송 템플릿 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return Result 성공 시 items 배열 포함
     */
    public function getList(int $domainId): Result
    {
        $templates = $this->shippingRepository->getList($domainId);
        $items = array_map(fn($tpl) => $tpl->toArray(), $templates);

        // 사용 중(상품에 설정된) 개수 배치 집계
        $usage = $this->shippingRepository->getUsageCountsByTemplateIds(
            $domainId,
            array_map('intval', array_column($items, 'shipping_id'))
        );
        foreach ($items as &$it) {
            $it['usage_count'] = $usage[(int) $it['shipping_id']] ?? 0;
        }
        unset($it);

        return Result::success('', ['items' => $items]);
    }

    /**
     * 배송 템플릿별 사용 상품 수 (여러 개 배치)
     * @param int[] $ids
     * @return array<int,int>
     */
    public function getUsageCounts(int $domainId, array $ids): array
    {
        return $this->shippingRepository->getUsageCountsByTemplateIds($domainId, $ids);
    }

    /**
     * 단일 배송 템플릿의 사용 상품 수
     */
    public function getUsageCount(int $domainId, int $shippingId): int
    {
        $counts = $this->shippingRepository->getUsageCountsByTemplateIds($domainId, [$shippingId]);
        return $counts[$shippingId] ?? 0;
    }

    /**
     * 배송 템플릿 상세 조회
     *
     * @param int $shippingId 배송 템플릿 ID
     * @return Result 성공 시 template 데이터 포함
     */
    public function getTemplate(int $domainId, int $shippingId): Result
    {
        $template = $this->shippingRepository->findInDomain($domainId, $shippingId);
        if (!$template) {
            return Result::failure('배송 템플릿을 찾을 수 없습니다.');
        }

        return Result::success('', ['template' => $template->toArray()]);
    }

    /**
     * 배송 템플릿 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 배송 템플릿 데이터
     * @return Result 성공 시 shipping_id 포함
     */
    public function create(int $domainId, array $data): Result
    {
        if ($message = $this->validateData($data)) {
            return Result::failure($message);
        }

        // 데이터 정규화
        $insertData = $this->normalizeData($data);
        $insertData['domain_id'] = $domainId;

        $shippingId = $this->shippingRepository->create($insertData);
        if (!$shippingId) {
            return Result::failure('배송 템플릿 생성에 실패했습니다.');
        }

        return Result::success('배송 템플릿이 생성되었습니다.', ['shipping_id' => $shippingId]);
    }

    /**
     * 배송 템플릿 수정
     *
     * @param int $shippingId 배송 템플릿 ID
     * @param array $data 수정할 데이터
     * @return Result
     */
    public function update(int $domainId, int $shippingId, array $data): Result
    {
        $template = $this->shippingRepository->findInDomain($domainId, $shippingId);
        if (!$template) {
            return Result::failure('배송 템플릿을 찾을 수 없습니다.');
        }

        if ($message = $this->validateData($data)) {
            return Result::failure($message);
        }

        $updateData = $this->normalizeData($data);

        $affected = $this->shippingRepository->updateInDomain($domainId, $shippingId, $updateData);
        if ($affected === false) {
            return Result::failure('배송 템플릿 수정에 실패했습니다.');
        }

        return Result::success('배송 템플릿이 수정되었습니다.');
    }

    /**
     * 배송 템플릿 삭제
     *
     * @param int $shippingId 배송 템플릿 ID
     * @return Result
     */
    public function delete(int $domainId, int $shippingId): Result
    {
        $template = $this->shippingRepository->findInDomain($domainId, $shippingId);
        if (!$template) {
            return Result::failure('배송 템플릿을 찾을 수 없습니다.');
        }

        if ($this->getUsageCount($domainId, $shippingId) > 0) {
            return Result::failure('상품에서 사용 중인 배송 템플릿은 삭제할 수 없습니다.');
        }

        $deleted = $this->shippingRepository->deleteInDomain($domainId, $shippingId);
        if (!$deleted) {
            return Result::failure('배송 템플릿 삭제에 실패했습니다.');
        }

        return Result::success('배송 템플릿이 삭제되었습니다.');
    }

    /**
     * 택배사 목록 조회
     *
     * @return Result 성공 시 companies 배열 포함
     */
    public function getDeliveryCompanies(): Result
    {
        $companies = $this->shippingRepository->getDeliveryCompanies();

        return Result::success('', ['companies' => $companies]);
    }

    /**
     * 배송 템플릿 데이터 정규화
     *
     * @param array $data 원본 데이터
     * @return array 정규화된 데이터
     */
    private function normalizeData(array $data): array
    {
        $normalized = [];

        $allowedFields = [
            'name', 'category', 'shipping_method', 'basic_cost',
            'price_ranges', 'free_threshold', 'goods_per_unit', 'extra_cost_enabled',
            'extra_cost_ranges',
            'return_cost', 'exchange_cost',
            'shipping_guide', 'delivery_method', 'delivery_company_id',
            'origin_zipcode', 'origin_address1', 'origin_address2',
            'return_zipcode', 'return_address1', 'return_address2',
            'is_active',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];

                // price_ranges / extra_cost_ranges는 JSON 인코딩
                if (in_array($field, ['price_ranges', 'extra_cost_ranges'], true) && is_array($value)) {
                    $value = json_encode($value);
                }

                // 정수 필드
                if (in_array($field, ['basic_cost', 'free_threshold', 'goods_per_unit', 'return_cost', 'exchange_cost', 'delivery_company_id'], true)) {
                    $value = (int) $value;
                }

                // 불린 필드
                if (in_array($field, ['extra_cost_enabled', 'is_active'], true)) {
                    $value = (bool) $value ? 1 : 0;
                }

                $normalized[$field] = $value;
            }
        }

        return $normalized;
    }

    /** 배송 방식별 필수값과 금액 범위를 검증한다. */
    private function validateData(array $data): ?string
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return '배송 템플릿 이름을 입력해주세요.';
        }

        $method = ShippingMethod::tryFrom((string) ($data['shipping_method'] ?? ShippingMethod::PAID->value));
        if ($method === null) {
            return '유효한 배송비 유형을 선택해주세요.';
        }

        foreach (['basic_cost', 'free_threshold', 'goods_per_unit', 'return_cost', 'exchange_cost'] as $field) {
            if (isset($data[$field]) && (int) $data[$field] < 0) {
                return '배송 관련 금액과 수량은 0 이상이어야 합니다.';
            }
        }

        if ($method === ShippingMethod::COND && (int) ($data['free_threshold'] ?? 0) <= 0) {
            return '조건부 무료 배송은 무료 배송 기준 금액이 필요합니다.';
        }
        if ($method === ShippingMethod::QUANTITY && (int) ($data['goods_per_unit'] ?? 0) <= 0) {
            return '수량별 배송은 배송비 부과 단위가 필요합니다.';
        }
        if ($method === ShippingMethod::AMOUNT && empty($data['price_ranges'])) {
            return '금액별 배송은 하나 이상의 금액 구간이 필요합니다.';
        }

        foreach (['price_ranges', 'extra_cost_ranges'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                return '배송비 구간 설정 형식이 올바르지 않습니다.';
            }
        }

        return null;
    }
}
