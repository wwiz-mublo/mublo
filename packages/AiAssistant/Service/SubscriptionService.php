<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\SubscriptionRepository;

final class SubscriptionService
{
    public function __construct(private SubscriptionRepository $subscriptions)
    {
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function summary(array $principal): array
    {
        $companyId = (string) $principal['company_id'];
        $plan = $this->subscriptions->currentPlan($companyId);
        $registered = $this->subscriptions->managedCustomerCount($companyId);
        $limit = (int) $plan['customer_limit'];

        return [
            'schema_version' => 'subscription-v1',
            'plan' => [
                'code' => (string) $plan['plan_code'],
                'name' => (string) $plan['name'],
                'monthly_price_krw' => $plan['monthly_price_krw'] === null
                    ? null
                    : (int) $plan['monthly_price_krw'],
                'customer_limit' => $limit,
            ],
            'usage' => [
                'registered_customers' => $registered,
                'remaining_customers' => max(0, $limit - $registered),
            ],
        ];
    }

    /** @param array<string, mixed> $record */
    public function assertCustomerProjectionAllowed(string $companyId, array $record): void
    {
        if (($record['object_type'] ?? null) !== 'customer'
            || ($record['operation'] ?? null) !== 'UPSERT'
            || ($record['payload']['management_status'] ?? null) !== 'MANAGED'
        ) {
            return;
        }

        $customerId = (string) $record['object_id'];
        if ($this->subscriptions->isActiveManagedCustomer($companyId, $customerId)) {
            return;
        }

        $plan = $this->subscriptions->lockCurrentPlan($companyId);
        $registered = $this->subscriptions->managedCustomerCount($companyId);
        $limit = (int) $plan['customer_limit'];
        if ($registered >= $limit) {
            throw new ApiException(
                'CUSTOMER_PLAN_LIMIT_EXCEEDED',
                sprintf('%s 요금제는 고객을 최대 %d명까지 등록할 수 있습니다.', (string) $plan['name'], $limit),
                409,
                [
                    'plan_code' => (string) $plan['plan_code'],
                    'plan_name' => (string) $plan['name'],
                    'customer_limit' => $limit,
                    'registered_customers' => $registered,
                ]
            );
        }
    }
}
