<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Packages\Shop\Enum\ShippingMethod;

/**
 * ShippingTemplate Entity
 *
 * 배송 템플릿 엔티티
 *
 * 책임:
 * - shop_shipping_templates 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class ShippingTemplate
{
    protected int $shippingId;
    protected int $domainId;
    protected ?string $name;
    protected ?string $category;

    // 배송 방식
    protected ShippingMethod $shippingMethod;
    protected int $basicCost;
    protected ?array $priceRanges;
    protected int $freeThreshold;
    protected int $goodsPerUnit;
    protected bool $extraCostEnabled;
    protected ?array $extraCostRanges;

    // 반품/교환
    protected int $returnCost;
    protected int $exchangeCost;

    // 배송 안내
    protected ?string $shippingGuide;
    protected ?string $deliveryMethod;
    protected ?int $deliveryCompanyId;

    // 출고지
    protected ?string $originZipcode;
    protected ?string $originAddress1;
    protected ?string $originAddress2;

    // 반품지
    protected ?string $returnZipcode;
    protected ?string $returnAddress1;
    protected ?string $returnAddress2;

    // 상태
    protected bool $isActive;

    // 시간
    protected string $createdAt;
    protected ?string $updatedAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->shippingId = (int) ($data['shipping_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->name = $data['name'] ?? null;
        $entity->category = $data['category'] ?? null;

        // 배송 방식
        $entity->shippingMethod = ShippingMethod::tryFrom($data['shipping_method'] ?? 'PAID') ?? ShippingMethod::PAID;
        $entity->basicCost = (int) ($data['basic_cost'] ?? 0);
        $entity->priceRanges = self::parseJson($data['price_ranges'] ?? null);
        $entity->freeThreshold = (int) ($data['free_threshold'] ?? 0);
        $entity->goodsPerUnit = (int) ($data['goods_per_unit'] ?? 0);
        $entity->extraCostEnabled = (bool) ($data['extra_cost_enabled'] ?? true);
        $entity->extraCostRanges = self::parseJson($data['extra_cost_ranges'] ?? null);

        // 반품/교환
        $entity->returnCost = (int) ($data['return_cost'] ?? 0);
        $entity->exchangeCost = (int) ($data['exchange_cost'] ?? 0);

        // 배송 안내
        $entity->shippingGuide = $data['shipping_guide'] ?? null;
        $entity->deliveryMethod = $data['delivery_method'] ?? null;
        $entity->deliveryCompanyId = isset($data['delivery_company_id']) ? (int) $data['delivery_company_id'] : null;

        // 출고지
        $entity->originZipcode = $data['origin_zipcode'] ?? null;
        $entity->originAddress1 = $data['origin_address1'] ?? null;
        $entity->originAddress2 = $data['origin_address2'] ?? null;

        // 반품지
        $entity->returnZipcode = $data['return_zipcode'] ?? null;
        $entity->returnAddress1 = $data['return_address1'] ?? null;
        $entity->returnAddress2 = $data['return_address2'] ?? null;

        // 상태
        $entity->isActive = (bool) ($data['is_active'] ?? false);

        // 시간
        $entity->createdAt = $data['created_at'] ?? '';
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'shipping_id' => $this->shippingId,
            'domain_id' => $this->domainId,
            'name' => $this->name,
            'category' => $this->category,
            'shipping_method' => $this->shippingMethod->value,
            'basic_cost' => $this->basicCost,
            'price_ranges' => $this->priceRanges !== null ? json_encode($this->priceRanges) : null,
            'free_threshold' => $this->freeThreshold,
            'goods_per_unit' => $this->goodsPerUnit,
            'extra_cost_enabled' => $this->extraCostEnabled,
            'extra_cost_ranges' => $this->extraCostRanges !== null ? json_encode($this->extraCostRanges) : null,
            'return_cost' => $this->returnCost,
            'exchange_cost' => $this->exchangeCost,
            'shipping_guide' => $this->shippingGuide,
            'delivery_method' => $this->deliveryMethod,
            'delivery_company_id' => $this->deliveryCompanyId,
            'origin_zipcode' => $this->originZipcode,
            'origin_address1' => $this->originAddress1,
            'origin_address2' => $this->originAddress2,
            'return_zipcode' => $this->returnZipcode,
            'return_address1' => $this->returnAddress1,
            'return_address2' => $this->returnAddress2,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getShippingId(): int
    {
        return $this->shippingId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getShippingMethod(): ShippingMethod
    {
        return $this->shippingMethod;
    }

    public function getBasicCost(): int
    {
        return $this->basicCost;
    }

    public function getPriceRanges(): ?array
    {
        return $this->priceRanges;
    }

    public function getFreeThreshold(): int
    {
        return $this->freeThreshold;
    }

    public function getGoodsPerUnit(): int
    {
        return $this->goodsPerUnit;
    }

    public function isExtraCostEnabled(): bool
    {
        return $this->extraCostEnabled;
    }

    public function getExtraCostRanges(): ?array
    {
        return $this->extraCostRanges;
    }

    public function getReturnCost(): int
    {
        return $this->returnCost;
    }

    public function getExchangeCost(): int
    {
        return $this->exchangeCost;
    }

    public function getShippingGuide(): ?string
    {
        return $this->shippingGuide;
    }

    public function getDeliveryMethod(): ?string
    {
        return $this->deliveryMethod;
    }

    public function getDeliveryCompanyId(): ?int
    {
        return $this->deliveryCompanyId;
    }

    public function getOriginZipcode(): ?string
    {
        return $this->originZipcode;
    }

    public function getOriginAddress1(): ?string
    {
        return $this->originAddress1;
    }

    public function getOriginAddress2(): ?string
    {
        return $this->originAddress2;
    }

    public function getReturnZipcode(): ?string
    {
        return $this->returnZipcode;
    }

    public function getReturnAddress1(): ?string
    {
        return $this->returnAddress1;
    }

    public function getReturnAddress2(): ?string
    {
        return $this->returnAddress2;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    // === 헬퍼 ===

    /**
     * JSON 문자열 파싱
     */
    private static function parseJson(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
