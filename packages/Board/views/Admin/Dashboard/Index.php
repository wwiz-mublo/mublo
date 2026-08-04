<?php
/**
 * Board Package - 관리자 대시보드
 *
 * @var string $pageTitle
 * @var int    $board_count
 * @var int    $group_count
 * @var int    $article_count
 * @var int    $comment_count
 * @var int    $today_articles
 * @var int    $today_comments
 * @var array  $activity_trend   [['date','label','articles','comments'], ...]
 * @var array  $top_boards       [['board_id','board_name','board_slug','cnt'], ...]
 * @var array  $recent_articles  [['article_id','title','board_name','author_name','comment_count','created_at'], ...]
 * @var array  $recent_comments  [['comment_id','content','article_id','article_title','board_name','created_at'], ...]
 */

$trendLabels   = array_column($activity_trend, 'label');
$trendArticles = array_map('intval', array_column($activity_trend, 'articles'));
$trendComments = array_map('intval', array_column($activity_trend, 'comments'));

$fmtDate = static function (?string $dt): string {
    if (empty($dt)) { return '-'; }
    $ts = strtotime($dt);
    return $ts ? date('Y-m-d', $ts) : '-';
};
?>
<link rel="stylesheet" href="<?= asset('/serve/package/Board/assets/css/admin.css') ?>">

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '대시보드') ?></h3>
            <p>게시판 현황을 한눈에 확인하세요.</p>
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
                        <div class="pastel-icon-blue board-metric-icon">
                            <i class="bi bi-collection"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-muted small">전체 게시판</div>
                            <div class="fw-bold fs-5"><?= number_format($board_count) ?>개</div>
                            <div class="text-muted" style="font-size:0.75rem">그룹 <?= number_format($group_count) ?>개</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pastel-icon-green board-metric-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-muted small">전체 게시글</div>
                            <div class="fw-bold fs-5"><?= number_format($article_count) ?>건</div>
                            <div class="text-muted" style="font-size:0.75rem">오늘 +<?= number_format($today_articles) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pastel-icon-orange board-metric-icon">
                            <i class="bi bi-chat-dots"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-muted small">전체 댓글</div>
                            <div class="fw-bold fs-5"><?= number_format($comment_count) ?>건</div>
                            <div class="text-muted" style="font-size:0.75rem">오늘 +<?= number_format($today_comments) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pastel-icon-purple board-metric-icon">
                            <i class="bi bi-pencil-square"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-muted small">오늘 작성</div>
                            <div class="fw-bold fs-5"><?= number_format($today_articles + $today_comments) ?>건</div>
                            <div class="text-muted" style="font-size:0.75rem">글 <?= number_format($today_articles) ?> · 댓글 <?= number_format($today_comments) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 활동 추이 + 게시판별 게시글 ── -->
    <div class="row g-3 mt-1">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-hero">
                    <i class="bi bi-bar-chart-line text-pastel-blue"></i>
                    <span>최근 14일 활동 추이</span>
                </div>
                <div class="card-body">
                    <div style="position: relative; height: 340px;">
                        <canvas id="boardActivityChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-hero">
                    <i class="bi bi-trophy text-pastel-orange"></i>
                    <span>게시판별 게시글 (상위 5)</span>
                </div>
                <div class="card-body">
                    <?php if (empty($top_boards)): ?>
                        <p class="text-muted text-center py-3">데이터가 없습니다.</p>
                    <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($top_boards as $idx => $board): ?>
                        <div class="d-flex align-items-center gap-3 p-2 rounded-2 board-top-item">
                            <div class="pastel-icon-orange board-rank-badge"><?= $idx + 1 ?></div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold small text-truncate">
                                    <?= htmlspecialchars($board['board_name'] ?? '(삭제된 게시판)') ?>
                                </div>
                            </div>
                            <div class="fw-bold small text-nowrap"><?= number_format((int) $board['cnt']) ?>건</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 최근 게시글 + 최근 댓글 ── -->
    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-hero">
                    <i class="bi bi-clock-history text-pastel-green"></i>
                    <span>최근 게시글</span>
                    <a href="/admin/board/article?activeCode=K_Board_005" class="text-muted text-decoration-none fw-normal ms-auto">전체 보기 <i class="bi bi-arrow-right-short"></i></a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead>
                                <tr>
                                    <th>제목</th>
                                    <th>게시판</th>
                                    <th>작성자</th>
                                    <th class="text-center">댓글</th>
                                    <th class="text-nowrap text-end pe-3">작성일</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recent_articles)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">게시글이 없습니다.</td></tr>
                            <?php else: ?>
                            <?php foreach ($recent_articles as $article): ?>
                            <tr>
                                <td>
                                    <a href="/admin/board/article/view?id=<?= (int) $article['article_id'] ?>&activeCode=K_Board_005"
                                       class="text-decoration-none fw-semibold">
                                        <span class="d-inline-block text-truncate align-bottom" style="max-width:220px">
                                            <?= htmlspecialchars($article['title']) ?>
                                        </span>
                                    </a>
                                </td>
                                <td class="text-muted text-nowrap"><?= htmlspecialchars($article['board_name'] ?? '-') ?></td>
                                <td class="text-muted text-nowrap"><?= htmlspecialchars($article['author_name'] ?? '-') ?></td>
                                <td class="text-center"><?= number_format((int) ($article['comment_count'] ?? 0)) ?></td>
                                <td class="text-muted text-nowrap text-end pe-3"><?= $fmtDate($article['created_at'] ?? null) ?></td>
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
                    <i class="bi bi-chat-left-text text-pastel-purple"></i>
                    <span>최근 댓글</span>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_comments)): ?>
                        <p class="text-muted text-center py-3">댓글이 없습니다.</p>
                    <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($recent_comments as $comment): ?>
                        <?php
                            $body = trim((string) ($comment['content'] ?? ''));
                            $body = mb_strimwidth(strip_tags($body), 0, 60, '…');
                        ?>
                        <a href="/admin/board/article/view?id=<?= (int) ($comment['article_id'] ?? 0) ?>&activeCode=K_Board_005"
                           class="d-block text-decoration-none p-2 rounded-2 board-top-item">
                            <div class="small text-body text-truncate"><?= htmlspecialchars($body !== '' ? $body : '(내용 없음)') ?></div>
                            <div class="text-muted d-flex gap-2 mt-1" style="font-size:0.72rem">
                                <span class="text-truncate"><?= htmlspecialchars($comment['board_name'] ?? '-') ?> · <?= htmlspecialchars($comment['article_title'] ?? '-') ?></span>
                                <span class="ms-auto text-nowrap"><?= $fmtDate($comment['created_at'] ?? null) ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/lib/chartjs/4.5.1/chart.umd.min.js"></script>
<script>
(function() {
    var labels   = <?= json_encode($trendLabels) ?>;
    var articles = <?= json_encode($trendArticles) ?>;
    var comments = <?= json_encode($trendComments) ?>;

    // 현재 테마의 실제 텍스트 색을 읽는다(라이트에는 --bs-body-color 변수가 없어
    // 계산된 body color를 소스로 사용). grid는 같은 색에 알파를 준다.
    function palette() {
        var text = (getComputedStyle(document.body).color || 'rgb(33,37,41)').trim();
        var grid = text.indexOf('rgb(') === 0
            ? text.replace('rgb(', 'rgba(').replace(')', ', 0.12)')
            : 'rgba(33,37,41,0.12)';
        return { text: text, grid: grid };
    }

    var ctx = document.getElementById('boardActivityChart');
    if (!ctx) { return; }

    var p = palette();
    Chart.defaults.color = p.text;
    Chart.defaults.borderColor = p.grid;

    var chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '게시글',
                    data: articles,
                    backgroundColor: 'rgba(59,130,246,0.25)',
                    borderColor: '#3b82f6',
                    borderWidth: 1.5,
                    borderRadius: 4,
                },
                {
                    label: '댓글',
                    data: comments,
                    type: 'line',
                    borderColor: '#8b5cf6',
                    backgroundColor: 'transparent',
                    pointBackgroundColor: '#8b5cf6',
                    tension: 0.3,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, color: p.text } } },
            scales: {
                x: { grid: { color: p.grid }, ticks: { color: p.text } },
                y: { beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 } },
            }
        }
    });

    // 테마 토글/초기 적용 시 data-bs-theme 변경을 감지해 색을 갱신한다.
    // (색을 생성 시점에 한 번만 읽으면 토글 후·최초 로드에서 글자색이 어긋남)
    function repaint() {
        var q = palette();
        Chart.defaults.color = q.text;
        Chart.defaults.borderColor = q.grid;
        chart.options.plugins.legend.labels.color = q.text;
        chart.options.scales.x.grid.color = q.grid;
        chart.options.scales.x.ticks.color = q.text;
        chart.options.scales.y.grid.color = q.grid;
        chart.options.scales.y.ticks.color = q.text;
        chart.update();
    }
    new MutationObserver(repaint).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-bs-theme']
    });
})();
</script>
