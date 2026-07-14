<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;

/**
 * Shop 관리자 대시보드 통계 서비스
 */
class DashboardService
{
    private OrderRepository $orderRepository;
    private ProductRepository $productRepository;

    public function __construct(
        OrderRepository $orderRepository,
        ProductRepository $productRepository
    ) {
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
    }

    /**
     * 오늘 매출 (결제완료 이상)
     */
    public function getTodayRevenue(int $domainId): int
    {
        return $this->orderRepository->sumRevenue($domainId, date('Y-m-d'), date('Y-m-d'));
    }

    /**
     * 이번 달 매출
     */
    public function getMonthRevenue(int $domainId): int
    {
        $start = date('Y-m-01');
        $end   = date('Y-m-t');
        return $this->orderRepository->sumRevenue($domainId, $start, $end);
    }

    /**
     * 오늘 신규 주문 수
     */
    public function getTodayOrderCount(int $domainId): int
    {
        return $this->orderRepository->countByDateRange($domainId, date('Y-m-d'), date('Y-m-d'));
    }

    /**
     * 처리 대기 주문 (주문접수 + 결제완료)
     */
    public function getPendingOrderCount(int $domainId): int
    {
        return $this->orderRepository->countByStatuses($domainId, ['received', 'paid']);
    }

    /**
     * 주문 상태별 카운트
     */
    public function getOrderStatusCounts(int $domainId): array
    {
        return $this->orderRepository->countGroupByStatus($domainId);
    }

    /**
     * 최근 주문 목록 (최대 10건)
     */
    public function getRecentOrders(int $domainId, int $limit = 10): array
    {
        return $this->orderRepository->getRecentOrders($domainId, $limit);
    }

    /**
     * 매출 추이 (최근 N일)
     */
    public function getRevenueTrend(int $domainId, int $days = 14): array
    {
        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = [
                'date'    => $date,
                'label'   => date('m/d', strtotime($date)),
                'revenue' => $this->orderRepository->sumRevenue($domainId, $date, $date),
                'orders'  => $this->orderRepository->countByDateRange($domainId, $date, $date),
            ];
        }
        return $trend;
    }

    /**
     * 판매 상위 상품 (최근 30일, 최대 5개)
     */
    public function getTopProducts(int $domainId, int $limit = 5): array
    {
        $start = date('Y-m-d', strtotime('-30 days'));
        $end   = date('Y-m-d');
        return $this->orderRepository->getTopProducts($domainId, $start, $end, $limit);
    }

    /**
     * 대시보드 전체 데이터 한 번에 로드
     */
    public function getDashboardData(int $domainId): array
    {
        return [
            'today_revenue'        => $this->getTodayRevenue($domainId),
            'month_revenue'        => $this->getMonthRevenue($domainId),
            'today_orders'         => $this->getTodayOrderCount($domainId),
            'pending_orders'       => $this->getPendingOrderCount($domainId),
            'order_status_counts'  => $this->getOrderStatusCounts($domainId),
            'recent_orders'        => $this->getRecentOrders($domainId),
            'revenue_trend'        => $this->getRevenueTrend($domainId),
            'top_products'         => $this->getTopProducts($domainId),
        ];
    }
}
