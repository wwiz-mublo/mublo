<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\OrderMemoRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;

/**
 * OrderMemoService
 *
 * 관리자 메모 비즈니스 로직
 *
 * 책임:
 * - 메모 추가/조회/삭제
 * - 메모 유형 검증
 */
class OrderMemoService
{
    private OrderMemoRepository $memoRepository;
    private OrderRepository $orderRepository;

    private const ALLOWED_TYPES = ['MEMO', 'CS_CALL', 'CS_CHAT', 'CS_EMAIL', 'INTERNAL'];

    public const TYPE_LABELS = [
        'MEMO' => '메모',
        'CS_CALL' => '전화',
        'CS_CHAT' => '채팅',
        'CS_EMAIL' => '이메일',
        'INTERNAL' => '내부',
    ];

    public function __construct(
        OrderMemoRepository $memoRepository,
        OrderRepository $orderRepository
    ) {
        $this->memoRepository = $memoRepository;
        $this->orderRepository = $orderRepository;
    }

    /**
     * 메모 추가
     */
    public function addMemo(string $orderNo, string $content, string $memoType, int $staffId): Result
    {
        if (empty(trim($content))) {
            return Result::failure('메모 내용을 입력해주세요.');
        }

        if (!in_array($memoType, self::ALLOWED_TYPES, true)) {
            $memoType = 'MEMO';
        }

        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $memoId = $this->memoRepository->createMemo([
            'order_no' => $orderNo,
            'memo_type' => $memoType,
            'content' => trim($content),
            'staff_id' => $staffId,
        ]);

        return Result::success('메모가 등록되었습니다.', ['memo_id' => $memoId]);
    }

    /**
     * 메모 목록 조회 (최신순)
     */
    public function getMemos(string $orderNo): array
    {
        return $this->memoRepository->getByOrderNo($orderNo);
    }

    /**
     * 메모 삭제
     */
    public function deleteMemo(int $memoId, int $staffId): Result
    {
        $memo = $this->memoRepository->findMemo($memoId);
        if (!$memo) {
            return Result::failure('메모를 찾을 수 없습니다.');
        }

        $this->memoRepository->deleteMemo($memoId);

        return Result::success('메모가 삭제되었습니다.');
    }
}
