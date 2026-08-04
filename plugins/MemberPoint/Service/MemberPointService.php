<?php
declare(strict_types=1);
namespace Mublo\Plugin\MemberPoint\Service;

use Mublo\Contract\Balance\BalanceGatewayInterface;

class MemberPointService
{
    private const SOURCE_TYPE = 'plugin';
    /** balance_logs 출처명. 리셋 시 자기 소스 행만 삭제하기 위해 공개한다. */
    public const SOURCE_NAME = 'MemberPoint';

    private const ACTION_MESSAGES = [
        'signup'   => '회원가입 포인트',
        'level_up' => '레벨업 포인트',
    ];

    public function __construct(
        private BalanceGatewayInterface $balanceManager,
        private MemberPointConfigService $configService,
    ) {}

    /**
     * 회원가입 포인트 지급
     */
    public function awardSignup(int $domainId, int $memberId): bool
    {
        $actionConfig = $this->configService->getActionConfig($domainId, 'signup');

        if (!$actionConfig['enabled'] || ($actionConfig['point'] ?? 0) <= 0) {
            return false;
        }

        $params = [
            'domain_id'       => $domainId,
            'member_id'       => $memberId,
            'amount'          => $actionConfig['point'],
            'source_type'     => self::SOURCE_TYPE,
            'source_name'     => self::SOURCE_NAME,
            'action'          => 'signup',
            'message'         => self::ACTION_MESSAGES['signup'],
            'idempotency_key' => "mp_signup_{$domainId}_{$memberId}",
        ];

        $result = $this->balanceManager->adjust($params);
        return $result->isSuccess();
    }

    /**
     * 레벨업 포인트 지급
     */
    public function awardLevelUp(int $domainId, int $memberId, int $newLevelValue): bool
    {
        $config = $this->configService->getLevelUpConfig($domainId);

        if (!$config['enabled']) {
            return false;
        }

        $levels = $config['levels'] ?? [];
        $point  = (int) ($levels[(string) $newLevelValue] ?? 0);

        if ($point <= 0) {
            return false;
        }

        $params = [
            'domain_id'       => $domainId,
            'member_id'       => $memberId,
            'amount'          => $point,
            'source_type'     => self::SOURCE_TYPE,
            'source_name'     => self::SOURCE_NAME,
            'action'          => 'level_up',
            'message'         => "레벨 {$newLevelValue} 달성 포인트",
            'idempotency_key' => "mp_levelup_{$domainId}_{$memberId}_{$newLevelValue}",
        ];

        $result = $this->balanceManager->adjust($params);
        return $result->isSuccess();
    }
}
