<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Packages\Shop\Enum\CouponType;

/**
 * CouponPolicy Entity
 *
 * 쿠폰 정책(쿠폰 그룹) 엔티티
 *
 * 책임:
 * - shop_coupon_group 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class CouponPolicy
{
    protected int $couponGroupId;
    protected int $domainId;
    protected string $name;
    protected ?string $description;

    // 쿠폰 유형
    protected CouponType $couponType;
    protected string $couponMethod;
    protected string $discountType;
    protected int $discountValue;
    protected ?int $maxDiscount;
    protected int $minOrderAmount;

    // 발행 기간
    protected ?string $issueStart;
    protected ?string $issueEnd;
    protected ?int $validDays;

    // 대상
    protected ?int $targetGoodsId;
    protected ?string $targetCategory;
    protected ?string $excludedGoods;
    protected ?string $excludedCategories;

    // 사용 정책
    protected string $duplicatePolicy;
    protected int $useLimitPerMember;
    protected int $downloadLimitPerMember;
    protected ?int $totalIssueLimit;
    protected ?string $allowedMemberLevels;
    protected bool $firstOrderOnly;

    // 자동 발행
    protected ?string $autoIssueTrigger;
    protected ?string $promotionCode;

    // 상태
    protected bool $isActive;
    protected ?int $staffId;

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

        $entity->couponGroupId = (int) ($data['coupon_group_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->name = $data['name'] ?? '';
        $entity->description = $data['description'] ?? null;

        // 쿠폰 유형
        $entity->couponType = CouponType::tryFrom($data['coupon_type'] ?? 'ADMIN') ?? CouponType::ADMIN;
        $entity->couponMethod = $data['coupon_method'] ?? 'ORDER';
        $entity->discountType = $data['discount_type'] ?? 'FIXED';
        $entity->discountValue = (int) ($data['discount_value'] ?? 0);
        $entity->maxDiscount = isset($data['max_discount']) ? (int) $data['max_discount'] : null;
        $entity->minOrderAmount = (int) ($data['min_order_amount'] ?? 0);

        // 발행 기간
        $entity->issueStart = $data['issue_start'] ?? null;
        $entity->issueEnd = $data['issue_end'] ?? null;
        $entity->validDays = isset($data['valid_days']) ? (int) $data['valid_days'] : null;

        // 대상
        $entity->targetGoodsId = isset($data['target_goods_id']) ? (int) $data['target_goods_id'] : null;
        $entity->targetCategory = $data['target_category'] ?? null;
        $entity->excludedGoods = $data['excluded_goods'] ?? null;
        $entity->excludedCategories = $data['excluded_categories'] ?? null;

        // 사용 정책
        $entity->duplicatePolicy = $data['duplicate_policy'] ?? 'DENY_SAME_METHOD';
        $entity->useLimitPerMember = (int) ($data['use_limit_per_member'] ?? 1);
        $entity->downloadLimitPerMember = (int) ($data['download_limit_per_member'] ?? 1);
        $entity->totalIssueLimit = isset($data['total_issue_limit']) ? (int) $data['total_issue_limit'] : null;
        $entity->allowedMemberLevels = $data['allowed_member_levels'] ?? null;
        $entity->firstOrderOnly = (bool) ($data['first_order_only'] ?? false);

        // 자동 발행
        $entity->autoIssueTrigger = $data['auto_issue_trigger'] ?? null;
        $entity->promotionCode = $data['promotion_code'] ?? null;

        // 상태
        $entity->isActive = (bool) ($data['is_active'] ?? true);
        $entity->staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;

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
            'coupon_group_id' => $this->couponGroupId,
            'domain_id' => $this->domainId,
            'name' => $this->name,
            'description' => $this->description,
            'coupon_type' => $this->couponType->value,
            'coupon_method' => $this->couponMethod,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'max_discount' => $this->maxDiscount,
            'min_order_amount' => $this->minOrderAmount,
            'issue_start' => $this->issueStart,
            'issue_end' => $this->issueEnd,
            'valid_days' => $this->validDays,
            'target_goods_id' => $this->targetGoodsId,
            'target_category' => $this->targetCategory,
            'excluded_goods' => $this->excludedGoods,
            'excluded_categories' => $this->excludedCategories,
            'duplicate_policy' => $this->duplicatePolicy,
            'use_limit_per_member' => $this->useLimitPerMember,
            'download_limit_per_member' => $this->downloadLimitPerMember,
            'total_issue_limit' => $this->totalIssueLimit,
            'allowed_member_levels' => $this->allowedMemberLevels,
            'first_order_only' => $this->firstOrderOnly,
            'auto_issue_trigger' => $this->autoIssueTrigger,
            'promotion_code' => $this->promotionCode,
            'is_active' => $this->isActive,
            'staff_id' => $this->staffId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getCouponGroupId(): int
    {
        return $this->couponGroupId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCouponType(): CouponType
    {
        return $this->couponType;
    }

    public function getCouponMethod(): string
    {
        return $this->couponMethod;
    }

    public function getDiscountType(): string
    {
        return $this->discountType;
    }

    public function getDiscountValue(): int
    {
        return $this->discountValue;
    }

    public function getMaxDiscount(): ?int
    {
        return $this->maxDiscount;
    }

    public function getMinOrderAmount(): int
    {
        return $this->minOrderAmount;
    }

    public function getIssueStart(): ?string
    {
        return $this->issueStart;
    }

    public function getIssueEnd(): ?string
    {
        return $this->issueEnd;
    }

    public function getValidDays(): ?int
    {
        return $this->validDays;
    }

    public function getTargetGoodsId(): ?int
    {
        return $this->targetGoodsId;
    }

    public function getTargetCategory(): ?string
    {
        return $this->targetCategory;
    }

    public function getExcludedGoods(): ?string
    {
        return $this->excludedGoods;
    }

    public function getExcludedCategories(): ?string
    {
        return $this->excludedCategories;
    }

    public function getDuplicatePolicy(): string
    {
        return $this->duplicatePolicy;
    }

    public function getUseLimitPerMember(): int
    {
        return $this->useLimitPerMember;
    }

    public function getDownloadLimitPerMember(): int
    {
        return $this->downloadLimitPerMember;
    }

    public function getAllowedMemberLevels(): ?string
    {
        return $this->allowedMemberLevels;
    }

    public function isFirstOrderOnly(): bool
    {
        return $this->firstOrderOnly;
    }

    public function getAutoIssueTrigger(): ?string
    {
        return $this->autoIssueTrigger;
    }

    public function getPromotionCode(): ?string
    {
        return $this->promotionCode;
    }

    public function getTotalIssueLimit(): ?int
    {
        return $this->totalIssueLimit;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getStaffId(): ?int
    {
        return $this->staffId;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }
}
