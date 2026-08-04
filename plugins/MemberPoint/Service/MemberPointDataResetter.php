<?php
declare(strict_types=1);

namespace Mublo\Plugin\MemberPoint\Service;

use Mublo\Contract\Balance\BalanceResetGatewayInterface;
use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;

class MemberPointDataResetter implements DataResettableInterface
{
    private const SOURCE_TYPE = 'plugin';

    public function __construct(private BalanceResetGatewayInterface $balanceResetGateway)
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('memberpoint', '포인트 내역', '회원 포인트 적립/차감 내역을 삭제하고 적립 포인트를 회수합니다. (설정은 보존)', 'bi-coin'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'memberpoint') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $deleted = $this->balanceResetGateway->resetSource(
            $domainId,
            self::SOURCE_TYPE,
            MemberPointService::SOURCE_NAME
        );

        return new DataResetResult(
            tablesCleared: $deleted > 0 ? 1 : 0,
            details: "MemberPoint 적립/차감 내역 {$deleted}건 삭제 + 적립 포인트 회수 (타 확장 내역·설정 보존)"
        );
    }
}
