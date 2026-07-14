<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * 상품 변경 이벤트
 *
 * 상품 CRUD 시 발행되어, 해당 상품이 진열된 블록 캐시 무효화 등을 유발한다.
 *
 * changeType:
 * - 'updated' : 가격/이미지/상품명 등 수정 (수동 진열 무효화)
 * - 'created' : 신규 등록 (자동 진열 멤버십 변동 가능)
 * - 'deleted' : 삭제 (수동/자동 진열 모두 변동)
 */
class ProductChangedEvent extends AbstractEvent
{
    public const UPDATED = 'updated';
    public const CREATED = 'created';
    public const DELETED = 'deleted';

    /**
     * @param int   $domainId  도메인 ID
     * @param int[] $goodsIds  변경된 상품 ID 목록
     * @param string $changeType updated|created|deleted
     */
    public function __construct(
        private readonly int $domainId,
        private readonly array $goodsIds,
        private readonly string $changeType,
    ) {}

    public function getDomainId(): int { return $this->domainId; }

    /** @return int[] */
    public function getGoodsIds(): array { return $this->goodsIds; }

    public function getChangeType(): string { return $this->changeType; }

    /** 멤버십 변동(자동 진열 영향) 여부 — 등록/삭제만 해당 */
    public function affectsAutoBlocks(): bool
    {
        return $this->changeType !== self::UPDATED;
    }
}
