<?php
declare(strict_types=1);

namespace Mublo\Contract\Notification;

/**
 * 알림 템플릿 편집·치환에 필요한 코어 문맥의 안정 계약.
 */
interface NotificationTemplateContextInterface
{
    /** @return array{shop_name:string, customer_tel:string, customer_time:string, domain:string} */
    public function loadShopSample(int $domainId): array;

    /** @return array<string, array<string, string>> */
    public function collectVariableGroups(?int $domainId = null): array;

    /** @return array<string, string> */
    public function getCompanySampleValues(int $domainId): array;
}
