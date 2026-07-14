<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\PointLogRepository;

class PointLogService
{
    private PointLogRepository $pointLogRepository;

    public function __construct(PointLogRepository $pointLogRepository)
    {
        $this->pointLogRepository = $pointLogRepository;
    }

    /**
     * 현재 잔액 조회
     */
    public function getBalance(int $domainId, int $memberId): int
    {
        return $this->pointLogRepository->getBalance($domainId, $memberId);
    }

    /**
     * 포인트 적립
     */
    public function earn(
        int $domainId,
        int $memberId,
        int $amount,
        string $reasonType,
        string $reasonDetail = '',
        array $refs = []
    ): Result {
        if ($amount <= 0) {
            return Result::failure('적립 금액은 0보다 커야 합니다.');
        }

        $balance = $this->getBalance($domainId, $memberId);
        $newBalance = $balance + $amount;

        $data = [
            'domain_id' => $domainId,
            'member_id' => $memberId,
            'point_type' => 'EARN',
            'point_amount' => $amount,
            'balance' => $newBalance,
            'reason_type' => $reasonType,
            'reason_detail' => $reasonDetail ?: null,
            'order_no' => $refs['order_no'] ?? null,
            'goods_id' => !empty($refs['goods_id']) ? (int) $refs['goods_id'] : null,
            'review_id' => !empty($refs['review_id']) ? (int) $refs['review_id'] : null,
            'expire_date' => $refs['expire_date'] ?? null,
            'staff_id' => !empty($refs['staff_id']) ? (int) $refs['staff_id'] : null,
        ];

        $id = $this->pointLogRepository->create($data);

        return $id
            ? Result::success('포인트가 적립되었습니다.', ['log_id' => $id, 'balance' => $newBalance])
            : Result::failure('포인트 적립에 실패했습니다.');
    }

    /**
     * 포인트 차감 (사용)
     */
    public function use(
        int $domainId,
        int $memberId,
        int $amount,
        string $reasonType = 'ORDER',
        array $refs = []
    ): Result {
        if ($amount <= 0) {
            return Result::failure('차감 금액은 0보다 커야 합니다.');
        }

        $balance = $this->getBalance($domainId, $memberId);
        if ($balance < $amount) {
            return Result::failure('포인트 잔액이 부족합니다.');
        }

        $newBalance = $balance - $amount;

        $data = [
            'domain_id' => $domainId,
            'member_id' => $memberId,
            'point_type' => 'USE',
            'point_amount' => -$amount,
            'balance' => $newBalance,
            'reason_type' => $reasonType,
            'reason_detail' => $refs['reason_detail'] ?? null,
            'order_no' => $refs['order_no'] ?? null,
            'goods_id' => null,
            'review_id' => null,
            'expire_date' => null,
            'staff_id' => null,
        ];

        $id = $this->pointLogRepository->create($data);

        return $id
            ? Result::success('포인트가 차감되었습니다.', ['log_id' => $id, 'balance' => $newBalance])
            : Result::failure('포인트 차감에 실패했습니다.');
    }

    /**
     * 포인트 환불 (주문 취소 시)
     */
    public function refund(
        int $domainId,
        int $memberId,
        int $amount,
        string $orderNo = ''
    ): Result {
        if ($amount <= 0) {
            return Result::failure('환불 금액은 0보다 커야 합니다.');
        }

        $balance = $this->getBalance($domainId, $memberId);
        $newBalance = $balance + $amount;

        $data = [
            'domain_id' => $domainId,
            'member_id' => $memberId,
            'point_type' => 'REFUND',
            'point_amount' => $amount,
            'balance' => $newBalance,
            'reason_type' => 'CANCEL',
            'reason_detail' => '주문 취소 환불',
            'order_no' => $orderNo ?: null,
            'goods_id' => null,
            'review_id' => null,
            'expire_date' => null,
            'staff_id' => null,
        ];

        $id = $this->pointLogRepository->create($data);

        return $id
            ? Result::success('포인트가 환불되었습니다.', ['balance' => $newBalance])
            : Result::failure('포인트 환불에 실패했습니다.');
    }

    /**
     * 포인트 이력 조회 (회원용)
     */
    public function getMemberHistory(int $domainId, int $memberId, int $page = 1, int $perPage = 20): array
    {
        return $this->pointLogRepository->getMemberLogs($domainId, $memberId, $page, $perPage);
    }

    /**
     * 포인트 이력 조회 (관리자용)
     */
    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->pointLogRepository->getList($domainId, $filters, $page, $perPage);
    }

    /**
     * 만료된 포인트 처리
     */
    public function processExpired(int $domainId): Result
    {
        $count = $this->pointLogRepository->expirePoints($domainId);
        return Result::success("{$count}건의 포인트가 만료 처리되었습니다.", ['expired_count' => $count]);
    }
}
