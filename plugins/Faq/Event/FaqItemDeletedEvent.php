<?php
declare(strict_types=1);

namespace Mublo\Plugin\Faq\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * FAQ 항목 삭제 뒤 이벤트 — 항목 하나를 지웠거나, 카테고리를 지워 그 안의 항목이 함께 지워졌을 때.
 * 패키지는 그 항목에 붙여 둔 자기 값(예: 연결 상품)을 정리한다.
 */
class FaqItemDeletedEvent extends AbstractEvent
{
    /**
     * @param int[] $faqIds
     */
    public function __construct(
        private readonly int $domainId,
        private readonly array $faqIds
    ) {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /** @return int[] */
    public function getFaqIds(): array
    {
        return $this->faqIds;
    }
}
