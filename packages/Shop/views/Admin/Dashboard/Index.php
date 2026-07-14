<?php
/**
 * 쇼핑몰 관리자 대시보드
 *
 * @var string $pageTitle
 * @var int    $today_revenue
 * @var int    $month_revenue
 * @var int    $today_orders
 * @var int    $pending_orders
 * @var array  $order_status_counts   [['order_status' => ..., 'cnt' => ...]]
 * @var array  $recent_orders
 * @var array  $revenue_trend         [['date' => ..., 'label' => ..., 'revenue' => ..., 'orders' => ...]]
 * @var array  $top_products          [['goods_id' => ..., 'goods_name' => ..., 'total_qty' => ..., 'total_revenue' => ...]]
 */

$statusLabels = [
    'received'         => '주문접수',
    'paid'             => '결제완료',
    'preparing'        => '상품준비',
    'shipping'         => '배송중',
    'delivered'        => '배송완료',
    'confirmed'        => '구매확정',
    'cancel_requested' => '취소요청',
    'cancelled'        => '취소완료',
    'return_requested' => '반품요청',
    'returned'         => '반품완료',
];

$statusCountMap = [];
foreach ($order_status_counts as $row) {
    $statusCountMap[$row['order_status']] = (int) $row['cnt'];
}

$trendLabels = array_column($revenue_trend, 'label');
$trendRevenue = array_column($revenue_trend, 'revenue');
$trendOrders  = array_column($revenue_trend, 'orders');
?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '대시보드') ?></h3>
            <p>쇼핑몰 현황을 한눈에 확인하세요.</p>
        </div>
        <div class="page-title-actions">
            <small class="text-muted"><?= date('Y년 m월 d일') ?> 기준</small>
        </div>
    </div>

    <!-- ── 메트릭 카드 (4개) ── -->
    <div class="row g-3 mt-2">
        <div class="col-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pastel-icon-blue shop-metric-icon">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-muted small">오늘 매출</div>
                            <div class="fw-bold fs-5"><?= number_format($today_revenue) ?>원</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pastel-icon-green shop-metric-icon">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-muted small">이번 달 매출</div>
                            <div class="fw-bold fs-5"><?= number_format($month_revenue) ?>원</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pastel-icon-orange shop-metric-icon">
                            <i class="bi bi-bag-check"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-muted small">오늘 주문</div>
                            <div class="fw-bold fs-5"><?= number_format($today_orders) ?>건</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pastel-icon-red shop-metric-icon">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-muted small">처리 대기</div>
                            <div class="fw-bold fs-5"><?= number_format($pending_orders) ?>건</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 매출 추이 차트 + 주문 상태 현황 ── -->
    <div class="row g-3 mt-1">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-hero">
                    <i class="bi bi-bar-chart-line text-pastel-blue"></i>
                    <span>최근 14일 매출 추이</span>
                </div>
                <div class="card-body">
                    <div style="position: relative; height: 400px;">
                        <canvas id="revenueTrendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-hero">
                    <i class="bi bi-pie-chart text-pastel-purple"></i>
                    <span>주문 상태 현황</span>
                </div>
                <div class="card-body">
                    <div style="position: relative; height: 340px;">
                        <canvas id="statusDonutChart"></canvas>
                    </div>
                    <div class="mt-2" id="statusLegend"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 최근 주문 + 상위 상품 ── -->
    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-hero">
                    <i class="bi bi-clock-history text-pastel-green"></i>
                    <span>최근 주문</span>
                    <a href="/admin/shop/orders?activeCode=K_Shop_005" class="text-muted text-decoration-none fw-normal ms-auto">전체 보기 <i class="bi bi-arrow-right-short"></i></a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead>
                                <tr>
                                    <th class="">주문번호</th>
                                    <th>상품</th>
                                    <th></th>
                                    <th class="text-end">금액</th>
                                    <th class="text-center pe-3">상태</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recent_orders)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">주문이 없습니다.</td></tr>
                            <?php else: ?>
                            <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td class="text-nowrap">
                                    <a href="/admin/shop/orders/<?= htmlspecialchars($order['order_no']) ?>?activeCode=K_Shop_005"
                                       class="text-decoration-none fw-semibold">
                                        <?= htmlspecialchars($order['order_no']) ?>
                                    </a>
                                </td>
                                <?php
                                    $firstItem = $order['first_item_name'] ?? '';
                                    $itemCount = (int) ($order['item_count'] ?? 0);
                                ?>
                                <td class="text-muted">
                                    <div class="text-truncate" style="max-width:240px">
                                        <?= $firstItem !== '' ? htmlspecialchars($firstItem) : '-' ?>
                                    </div>
                                </td>
                                <td class="text-muted text-nowrap small">
                                    <?= $itemCount > 1 ? '외 ' . ($itemCount - 1) . '건' : '' ?>
                                </td>
                                <td class="text-nowrap text-end fw-semibold"><?= number_format((int) $order['final_price']) ?>원</td>
                                <td class="text-nowrap text-center">
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary">
                                        <?= htmlspecialchars($statusLabels[$order['order_status']] ?? $order['order_status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-hero">
                    <i class="bi bi-trophy text-pastel-orange"></i>
                    <span>판매 상위 상품 (최근 30일)</span>
                </div>
                <div class="card-body">
                    <?php if (empty($top_products)): ?>
                        <p class="text-muted text-center py-3">데이터가 없습니다.</p>
                    <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($top_products as $idx => $product): ?>
                        <div class="d-flex align-items-center gap-3 p-2 rounded-2 shop-top-product">
                            <div class="pastel-icon-orange shop-rank-badge">
                                <?= $idx + 1 ?>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold small text-truncate">
                                    <?= htmlspecialchars($product['goods_name']) ?>
                                </div>
                                <div class="text-muted" style="font-size:0.75rem">
                                    <?= number_format((int) $product['total_qty']) ?>개 판매
                                </div>
                            </div>
                            <div class="fw-bold small text-nowrap">
                                <?= number_format((int) $product['total_revenue']) ?>원
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function() {
    var trendLabels  = <?= json_encode($trendLabels) ?>;
    var trendRevenue = <?= json_encode($trendRevenue) ?>;
    var trendOrders  = <?= json_encode($trendOrders) ?>;

    var statusMap = <?= json_encode($statusCountMap) ?>;
    var statusLabels = <?= json_encode($statusLabels) ?>;

    // 현재 테마의 실제 텍스트 색을 소스로 사용(라이트엔 --bs-body-color 변수가 없음).
    // grid는 같은 색에 알파를 준다.
    function palette() {
        var text = (getComputedStyle(document.body).color || 'rgb(33,37,41)').trim();
        var grid = text.indexOf('rgb(') === 0
            ? text.replace('rgb(', 'rgba(').replace(')', ', 0.12)')
            : 'rgba(33,37,41,0.12)';
        return { text: text, grid: grid };
    }

    var p = palette();
    Chart.defaults.color = p.text;
    Chart.defaults.borderColor = p.grid;

    var trendChart = null;

    // ── 매출 추이 차트 ──
    var ctx1 = document.getElementById('revenueTrendChart');
    if (ctx1) {
        trendChart = new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: '매출(원)',
                        data: trendRevenue,
                        backgroundColor: 'rgba(129,140,248,0.25)',
                        borderColor: '#818cf8',
                        borderWidth: 1.5,
                        borderRadius: 4,
                        yAxisID: 'y',
                    },
                    {
                        label: '주문수',
                        data: trendOrders,
                        type: 'line',
                        borderColor: '#34d399',
                        backgroundColor: 'transparent',
                        pointBackgroundColor: '#34d399',
                        tension: 0.3,
                        yAxisID: 'y2',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, color: p.text } } },
                scales: {
                    x:  { grid: { color: p.grid }, ticks: { color: p.text } },
                    y:  { beginAtZero: true, position: 'left',  grid: { color: p.grid }, ticks: { color: p.text, callback: v => (v/10000).toFixed(0) + '만' } },
                    y2: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { color: p.text } },
                }
            }
        });
    }

    // ── 주문 상태 도넛 ──
    var ctx2 = document.getElementById('statusDonutChart');
    if (ctx2) {
        var statusKeys = Object.keys(statusMap);
        var statusValues = statusKeys.map(k => statusMap[k]);
        var statusDisplayLabels = statusKeys.map(k => statusLabels[k] || k);
        var COLORS = ['#818cf8','#34d399','#fbbf24','#38bdf8','#f472b6','#fb923c','#f87171','#a78bfa','#2dd4bf','#a3e635'];

        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: statusDisplayLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: COLORS.slice(0, statusKeys.length),
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: { legend: { display: false } },
            }
        });

        // 범례 렌더링
        var legendEl = document.getElementById('statusLegend');
        if (legendEl && statusKeys.length > 0) {
            legendEl.innerHTML = statusKeys.map(function(k, i) {
                var count = statusMap[k] || 0;
                return '<div class="d-flex align-items-center gap-2 mb-1">'
                    + '<span style="width:10px;height:10px;border-radius:50%;background:' + COLORS[i] + ';flex-shrink:0"></span>'
                    + '<span class="small text-muted flex-grow-1">' + (statusLabels[k] || k) + '</span>'
                    + '<span class="small fw-semibold">' + count.toLocaleString() + '건</span>'
                    + '</div>';
            }).join('');
        }
    }

    // 테마 토글/초기 적용 시 data-bs-theme 변경을 감지해 축·범례·그리드 색을 갱신한다.
    // (색을 생성 시점에 한 번만 읽으면 토글 후·최초 로드에서 글자색이 어긋남)
    // 도넛 범례는 CSS(text-muted 등)로 렌더돼 테마에 자동 반응하므로 별도 갱신 불필요.
    function repaint() {
        var q = palette();
        Chart.defaults.color = q.text;
        Chart.defaults.borderColor = q.grid;
        if (trendChart) {
            trendChart.options.plugins.legend.labels.color = q.text;
            trendChart.options.scales.x.grid.color = q.grid;
            trendChart.options.scales.x.ticks.color = q.text;
            trendChart.options.scales.y.grid.color = q.grid;
            trendChart.options.scales.y.ticks.color = q.text;
            trendChart.options.scales.y2.ticks.color = q.text;
            trendChart.update();
        }
    }
    new MutationObserver(repaint).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-bs-theme']
    });
})();
</script>
