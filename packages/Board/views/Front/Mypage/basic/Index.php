<?php
/**
 * Board - 마이페이지 허브 "마이보드" (대시보드). 코어 셸(Section.php) 안에 렌더.
 *
 * 게시판별 글/댓글 통계 + 그래프. 링크는 게시판 본체(/board/{slug})로만.
 * (내가 쓴 글/댓글 목록은 /mypage/board/articles·comments 섹션이 담당)
 *
 * @var array $boards        게시판별 [{board_name, board_slug, articles, comments}] (활동량 desc)
 * @var int   $totalArticles 총 작성 글
 * @var int   $totalComments 총 작성 댓글
 */
$boards         = $boards ?? [];
$totalArticles  = (int) ($totalArticles ?? 0);
$totalComments  = (int) ($totalComments ?? 0);
$totalReactions = (int) ($totalReactions ?? 0);
$hasActivity    = !empty($boards);

// 최근 30일 일별 추이 (글/댓글/반응)
$trendLabels    = $trendLabels ?? [];
$trendArticles  = $trendArticles ?? [];
$trendComments  = $trendComments ?? [];
$trendReactions = $trendReactions ?? [];
// 차트는 활동량 상위 8개 게시판만 (게시판이 많아도 그래프가 안 깨지도록). 표는 전체 노출.
$chartBoards = array_slice($boards, 0, 8);
$labels   = array_map(fn($b) => ($b['board_name'] !== '' ? $b['board_name'] : '(기타)'), $chartBoards);
$articles = array_map(fn($b) => (int) $b['articles'], $chartBoards);
$comments = array_map(fn($b) => (int) $b['comments'], $chartBoards);
$totals   = array_map(fn($b) => (int) $b['articles'] + (int) $b['comments'], $chartBoards);

$this->assets->addCss('/serve/package/Board/views/Front/Mypage/basic/_assets/css/mypage.css');
?>

<div class="myboard">
    <div class="mypage-header">
        <h1 class="mypage-header__title">마이보드</h1>
        <p class="mypage-header__desc">내 게시판 활동을 한눈에 봅니다.</p>
    </div>

    <div class="myboard__stats">
        <div class="myboard__stat">
            <div class="myboard__stat-num"><?= number_format($totalArticles) ?></div>
            <div class="myboard__stat-label">작성 글</div>
        </div>
        <div class="myboard__stat">
            <div class="myboard__stat-num"><?= number_format($totalComments) ?></div>
            <div class="myboard__stat-label">작성 댓글</div>
        </div>
        <div class="myboard__stat">
            <div class="myboard__stat-num"><?= number_format($totalReactions) ?></div>
            <div class="myboard__stat-label">보낸 반응</div>
        </div>
    </div>

    <?php if (!$hasActivity): ?>
        <div class="empty-state">아직 커뮤니티 활동이 없습니다.</div>
    <?php else: ?>
        <?php if (count($boards) > 8): ?>
            <p class="myboard__caption">그래프는 활동 상위 8개 게시판 기준 (전체는 아래 표)</p>
        <?php endif; ?>
        <div class="myboard__charts">
            <div class="myboard__chart--bar"><canvas id="boardActivityBar"></canvas></div>
            <div class="myboard__chart--donut"><canvas id="boardActivityDonut"></canvas></div>
        </div>

        <h3 class="myboard__subtitle">최근 30일 활동</h3>
        <div class="myboard__chart--trend"><canvas id="boardActivityTrend"></canvas></div>

        <table class="mypage-list-table">
            <thead>
                <tr><th>게시판</th><th>글</th><th>댓글</th><th>합계</th></tr>
            </thead>
            <tbody>
                <?php foreach ($boards as $b):
                    $slug = $b['board_slug'] ?? '';
                    $url  = $slug !== '' ? "/board/{$slug}" : '#';
                    $name = $b['board_name'] !== '' ? $b['board_name'] : '(기타)';
                    $sum  = (int) $b['articles'] + (int) $b['comments'];
                ?>
                <tr>
                    <td class="board-name"><a href="<?= e($url) ?>"><?= e($name) ?></a></td>
                    <td class="meta"><?= number_format((int) $b['articles']) ?></td>
                    <td class="meta"><?= number_format((int) $b['comments']) ?></td>
                    <td class="meta"><?= number_format($sum) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($hasActivity): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function () {
    var labels   = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    var articles = <?= json_encode($articles) ?>;
    var comments = <?= json_encode($comments) ?>;
    var totals   = <?= json_encode($totals) ?>;
    var palette_ = ['#667eea','#22c55e','#f59e0b','#ef4444','#14b8a6','#f472b6','#a5b4fc','#8b5cf6','#10b981','#fb923c'];

    var trendLabels    = <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>;
    var trendArticles  = <?= json_encode($trendArticles) ?>;
    var trendComments  = <?= json_encode($trendComments) ?>;
    var trendReactions = <?= json_encode($trendReactions) ?>;

    var charts = [];

    // 현재 테마의 텍스트·격자 색을 토큰(--muted-foreground/--border)에서 읽는다.
    // tokens.css가 .dark/[data-theme="dark"]에서 두 토큰을 자동 반전한다.
    function palette() {
        var cs = getComputedStyle(document.documentElement);
        function px(name, fallback) {
            var v = cs.getPropertyValue(name).trim();
            return v !== '' ? v : fallback;
        }
        return { text: px('--muted-foreground', '#6b7280'), grid: px('--border', 'rgba(0,0,0,.1)') };
    }

    function draw() {
        if (typeof Chart === 'undefined') { return setTimeout(draw, 50); }

        var p = palette();
        Chart.defaults.color = p.text;
        Chart.defaults.borderColor = p.grid;

        charts.push(new Chart(document.getElementById('boardActivityBar'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: '글',   data: articles, backgroundColor: '#667eea' },
                    { label: '댓글', data: comments, backgroundColor: '#a5b4fc' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { grid: { color: p.grid }, ticks: { color: p.text } },
                    y: { beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 } }
                },
                plugins: { legend: { position: 'bottom', labels: { color: p.text } } }
            }
        }));

        charts.push(new Chart(document.getElementById('boardActivityDonut'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: totals, backgroundColor: totals.map(function (_, i) { return palette_[i % palette_.length]; }) }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: p.text } } }
            }
        }));

        charts.push(new Chart(document.getElementById('boardActivityTrend'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    { label: '글',   data: trendArticles,  borderColor: '#667eea', backgroundColor: '#667eea', tension: .3, pointRadius: 0 },
                    { label: '댓글', data: trendComments,  borderColor: '#22c55e', backgroundColor: '#22c55e', tension: .3, pointRadius: 0 },
                    { label: '반응', data: trendReactions, borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: .3, pointRadius: 0 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { grid: { color: p.grid }, ticks: { color: p.text } },
                    y: { beginAtZero: true, grid: { color: p.grid }, ticks: { color: p.text, precision: 0 } }
                },
                plugins: { legend: { position: 'bottom', labels: { color: p.text } } }
            }
        }));

        // 테마 토글(.dark/[data-theme]) 시 색을 다시 읽어 반영한다.
        // (defaults만 한 번 읽으면 토글 후 축·범례 색이 어긋남 → 명시적으로 갱신)
        function repaint() {
            var q = palette();
            Chart.defaults.color = q.text;
            Chart.defaults.borderColor = q.grid;
            charts.forEach(function (ch) {
                if (ch.options.plugins && ch.options.plugins.legend) {
                    ch.options.plugins.legend.labels.color = q.text;
                }
                if (ch.options.scales && ch.options.scales.x) {
                    ch.options.scales.x.grid.color = q.grid;
                    ch.options.scales.x.ticks.color = q.text;
                    ch.options.scales.y.grid.color = q.grid;
                    ch.options.scales.y.ticks.color = q.text;
                }
                ch.update();
            });
        }
        new MutationObserver(repaint).observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'data-theme']
        });
    }
    draw();
})();
</script>
<?php endif; ?>
