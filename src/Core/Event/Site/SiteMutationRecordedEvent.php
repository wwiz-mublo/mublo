<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Site;

use Mublo\Contract\Site\SiteMutationReceipt;
use Mublo\Core\Event\AbstractEvent;

/** 최상위 트랜잭션 commit 뒤 발행되는 사이트 변경 이벤트. */
final class SiteMutationRecordedEvent extends AbstractEvent
{
    public function __construct(public readonly SiteMutationReceipt $receipt)
    {
    }
}
