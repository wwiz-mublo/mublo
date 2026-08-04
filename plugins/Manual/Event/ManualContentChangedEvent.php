<?php
declare(strict_types=1);

namespace Mublo\Plugin\Manual\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * 매뉴얼 책/페이지 변경 완료 이벤트.
 *
 * DB 트랜잭션이 끝난 뒤 발행하며 블록 캐시처럼 파생된 읽기 결과를 갱신한다.
 */
final class ManualContentChangedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly string $changeType,
    ) {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getChangeType(): string
    {
        return $this->changeType;
    }
}
