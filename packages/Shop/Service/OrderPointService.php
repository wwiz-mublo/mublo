<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Contract\Balance\BalanceGatewayInterface;
use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Log\Logger;

/**
 * OrderPointService
 *
 * 쇼핑몰 "포인트 결제(사용)" 전용 서비스.
 * 게시판/적립 등과 동일한 중앙 잔액(BalanceGatewayInterface)을 사용한다.
 *
 * - getUsageContext(): 프론트 노출/검증에 필요한 설정·한도·잔액
 * - validate():        서버측 사용 포인트 검증 (단위/최소/최대/잔액/주문금액)
 * - hold():            주문 생성 직후 포인트를 선점(즉시 차감, 멱등)
 * - confirm():         결제 완료 시 선점 이력을 확인(멱등)
 * - release():         취소/실패/반품 시 선점 포인트 복원(멱등)
 *
 * 선점 시점은 결제 방식과 무관하게 주문 생성 직후다. BalanceManager의 행 잠금으로
 * 동시 주문이 같은 잔액을 중복 사용하는 것을 막고, 취소/실패/반품에서 release()한다.
 *
 * 적립(지급)은 PointActionHandler가, 사용(차감)은 본 서비스가 담당한다.
 */
class OrderPointService
{
    /** 원장 기록용 출처명 (적립/회수와 동일) */
    private const SOURCE_NAME = 'Shop';

    public function __construct(
        private BalanceGatewayInterface $balanceManager,
        private ShopConfigService $shopConfigService,
        private MemberLevelCatalogInterface $memberLevelService,
        private ?Logger $logger = null,
    ) {}

    /**
     * 포인트 사용 컨텍스트 (설정 + 회원 한도 + 잔액)
     *
     * @return array{enabled:bool, unit:int, min:int, max:int, balance:int}
     */
    public function getUsageContext(int $domainId, int $memberId, int $levelValue): array
    {
        $config = $this->shopConfigService->getConfig($domainId)->get('config', []);

        $enabled = !empty($config['use_point_payment']) && $memberId > 0;
        $unit = max(1, (int) ($config['point_unit'] ?? 100));

        [$min, $max] = $this->resolveLimits($config, $levelValue);

        $balance = $memberId > 0 ? $this->balanceManager->getBalance($memberId, $domainId) : 0;

        return [
            'enabled' => $enabled,
            'unit' => $unit,
            'min' => $min,
            'max' => $max,
            'balance' => $balance,
        ];
    }

    /**
     * 레벨별/전역 최소·최대 사용 한도 해석
     *
     * point_level_settings(레벨별)가 있으면 회원 레벨에 해당하는 값을,
     * 없으면 전역 point_min/point_max를 사용한다.
     *
     * @return array{0:int, 1:int} [min, max]
     */
    private function resolveLimits(array $config, int $levelValue): array
    {
        $globalMin = (int) ($config['point_min'] ?? 0);
        $globalMax = (int) ($config['point_max'] ?? 0);

        $levelSettings = $config['point_level_settings'] ?? null;
        if (is_string($levelSettings)) {
            $levelSettings = json_decode($levelSettings, true);
        }
        if (!is_array($levelSettings) || empty($levelSettings)) {
            return [$globalMin, $globalMax];
        }

        $level = $this->memberLevelService->findByValue($levelValue);
        if ($level === null) {
            return [$globalMin, $globalMax];
        }

        $ls = $levelSettings[$level->levelId] ?? null;
        if (!is_array($ls)) {
            return [$globalMin, $globalMax];
        }

        return [
            (int) ($ls['min'] ?? $globalMin),
            (int) ($ls['max'] ?? $globalMax),
        ];
    }

    /**
     * 사용 포인트 검증
     *
     * @param int   $pointUsed 사용 요청 포인트 (0 허용 = 미사용)
     * @param array $ctx       getUsageContext() 결과
     * @param int   $payable   포인트 적용 전 결제 예정 금액 (상품+배송 등)
     */
    public function validate(int $pointUsed, array $ctx, int $payable): Result
    {
        if ($pointUsed <= 0) {
            return Result::success('포인트 미사용');
        }

        if (empty($ctx['enabled'])) {
            return Result::failure('포인트 결제를 사용할 수 없습니다.');
        }

        $unit = max(1, (int) $ctx['unit']);
        if ($pointUsed % $unit !== 0) {
            return Result::failure("포인트는 {$unit}P 단위로 사용할 수 있습니다.");
        }

        $min = (int) ($ctx['min'] ?? 0);
        if ($min > 0 && $pointUsed < $min) {
            return Result::failure('최소 ' . number_format($min) . 'P 이상 사용해야 합니다.');
        }

        $max = (int) ($ctx['max'] ?? 0);
        if ($max > 0 && $pointUsed > $max) {
            return Result::failure('최대 ' . number_format($max) . 'P까지 사용할 수 있습니다.');
        }

        if ($pointUsed > (int) ($ctx['balance'] ?? 0)) {
            return Result::failure('보유 포인트가 부족합니다.');
        }

        if ($pointUsed > $payable) {
            return Result::failure('사용 포인트가 결제 금액을 초과할 수 없습니다.');
        }

        return Result::success('사용 가능');
    }

    /**
     * 사용 포인트 차감 (멱등)
     *
     * @param array $order 주문 데이터 (order_no, member_id, domain_id, point_used)
     */
    public function hold(array $order): Result
    {
        $orderNo = (string) ($order['order_no'] ?? '');
        $memberId = (int) ($order['member_id'] ?? 0);
        $domainId = (int) ($order['domain_id'] ?? 1);
        $pointUsed = (int) ($order['point_used'] ?? 0);

        if ($orderNo === '' || $memberId <= 0 || $pointUsed <= 0) {
            return Result::success('차감 대상 아님');
        }

        try {
            $result = $this->balanceManager->adjust([
                'domain_id' => $domainId,
                'member_id' => $memberId,
                'amount' => -$pointUsed,
                'source_type' => 'package',
                'source_name' => self::SOURCE_NAME,
                'action' => 'order_point_hold',
                'message' => '주문 포인트 선점',
                'reference_type' => 'shop_order',
                'reference_id' => $orderNo,
                'idempotency_key' => "shop_point_hold_{$orderNo}",
            ]);
        } catch (\Throwable $e) {
            $result = Result::failure($e->getMessage());
        }

        if ($result->isFailure()) {
            $this->logger?->warning('Shop 포인트 선점 실패', [
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'point_used' => $pointUsed,
                'error' => $result->getMessage(),
            ]);
        }

        return $result;
    }

    /** 결제 완료 경계에서 해당 주문의 선점이 존재하는지 확인한다. */
    public function confirm(array $order): Result
    {
        $orderNo = (string) ($order['order_no'] ?? '');
        $memberId = (int) ($order['member_id'] ?? 0);
        $domainId = (int) ($order['domain_id'] ?? 1);
        $pointUsed = (int) ($order['point_used'] ?? 0);

        if ($orderNo === '' || $memberId <= 0 || $pointUsed <= 0) {
            return Result::success('확정 대상 아님');
        }

        $holdLog = $this->balanceManager->findLogByIdempotencyKey("shop_point_hold_{$orderNo}", $domainId);
        if ($holdLog === null) {
            return Result::failure('주문 포인트 선점 이력을 찾을 수 없습니다.');
        }
        $releaseLog = $this->balanceManager->findLogByIdempotencyKey("shop_point_release_{$orderNo}", $domainId);
        return $releaseLog === null
            ? Result::success('포인트 선점 확인 완료')
            : Result::failure('이미 해제된 주문 포인트 선점입니다.');
    }

    /**
     * 사용 포인트 복원 (실제 차감된 주문에 한해, 멱등)
     *
     * PG 미완료로 취소된 주문(차감 이력 없음)은 복원하지 않는다.
     *
     * @param array $order 주문 데이터 (order_no, member_id, domain_id, point_used)
     */
    public function release(array $order): Result
    {
        $orderNo = (string) ($order['order_no'] ?? '');
        $memberId = (int) ($order['member_id'] ?? 0);
        $domainId = (int) ($order['domain_id'] ?? 1);
        $pointUsed = (int) ($order['point_used'] ?? 0);

        if ($orderNo === '' || $memberId <= 0 || $pointUsed <= 0) {
            return Result::success('복원 대상 아님');
        }

        // 실제 차감 이력이 있는 주문만 복원 (선점/결제 완료된 적 없으면 복원 불필요)
        $holdLog = $this->balanceManager->findLogByIdempotencyKey("shop_point_hold_{$orderNo}", $domainId);
        if ($holdLog === null) {
            return Result::success('선점 이력 없음 - 복원 불필요');
        }

        try {
            $result = $this->balanceManager->adjust([
                'domain_id' => $domainId,
                'member_id' => $memberId,
                'amount' => $pointUsed,
                'source_type' => 'package',
                'source_name' => self::SOURCE_NAME,
                'action' => 'order_point_release',
                'message' => '주문 포인트 선점 해제',
                'reference_type' => 'shop_order',
                'reference_id' => $orderNo,
                'idempotency_key' => "shop_point_release_{$orderNo}",
            ]);
        } catch (\Throwable $e) {
            $result = Result::failure($e->getMessage());
        }

        if ($result->isFailure()) {
            $this->logger?->warning('Shop 포인트 사용 복원 실패', [
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'point_used' => $pointUsed,
                'error' => $result->getMessage(),
            ]);
        }

        return $result;
    }

    /** @deprecated 호환용 별칭. 신규 코드는 hold()를 사용한다. */
    public function reserve(array $order): Result
    {
        return $this->hold($order);
    }

    /** @deprecated 호환용 별칭. 신규 코드는 release()를 사용한다. */
    public function restore(array $order): Result
    {
        return $this->release($order);
    }
}
