<?php
/**
 * @var string $pageTitle
 * @var array  $survey
 * @var int    $totalResponses
 * @var array  $questions
 */
$statusLabel = ['draft' => '초안', 'active' => '진행중', 'closed' => '종료'];
$statusBadge = ['draft' => 'warning', 'active' => 'success', 'closed' => 'dark'];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>설문 응답 결과를 확인합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="<?= htmlspecialchars($listUrl ?? '/admin/survey/surveys') ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <a href="/admin/survey/surveys/<?= $survey['survey_id'] ?>/edit"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil"></i> 설문 편집
            </a>
        </div>
    </div>

    <!-- 요약 카드 -->
    <div class="row g-3">
        <div class="col-md">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">설문 제목</div>
                    <div class="fw-semibold"><?= htmlspecialchars($survey['title']) ?></div>
                    <?php if ($survey['description']): ?>
                    <div class="text-muted small mt-1"><?= htmlspecialchars($survey['description']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-auto">
            <div class="card h-100">
                <div class="card-body text-center px-4">
                    <div class="text-muted small mb-1">총 응답</div>
                    <div class="fs-2 fw-bold text-primary"><?= number_format($totalResponses) ?></div>
                    <div class="text-muted small">건</div>
                </div>
            </div>
        </div>
        <div class="col-md-auto">
            <div class="card h-100">
                <div class="card-body text-center px-4">
                    <div class="text-muted small mb-1">상태</div>
                    <?php $sc = $statusBadge[$survey['status']] ?? 'secondary'; ?>
                    <span class="badge bg-<?= $sc ?>-subtle text-<?= $sc ?>-emphasis border border-<?= $sc ?>-subtle fs-6">
                        <?= $statusLabel[$survey['status']] ?? $survey['status'] ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- 질문별 결과 (응답이 없어도 문항 구성은 표시) -->
    <?php if (empty($questions)): ?>
    <div class="text-center text-muted py-5 mt-4">질문이 없는 설문입니다.</div>
    <?php else: ?>
    <div class="page-block mt-4">
        <?php foreach ($questions as $no => $q): ?>
        <div class="card mb-4">
            <div class="card-hero">
                <span class="text-muted small">Q<?= $no + 1 ?></span>
                <span class="text-pastel-purple"><?= htmlspecialchars($q['title']) ?></span>
                <span class="badge bg-body-secondary text-body border small"><?= htmlspecialchars($q['type']) ?></span>
            </div>
            <div class="card-body">
                <?php if (in_array($q['type'], ['radio', 'checkbox', 'select'])): ?>
                    <!-- 선택형: CSS 바 차트 -->
                    <?php foreach ($q['stats'] as $stat): ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= htmlspecialchars($stat['label']) ?></span>
                            <span class="text-muted">
                                <?= number_format($stat['count']) ?>건 (<?= $stat['pct'] ?>%)
                            </span>
                        </div>
                        <div class="survey-bar-track">
                            <div class="survey-bar-fill" style="width:<?= min(100, $stat['pct']) ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                <?php elseif ($q['type'] === 'rating'): ?>
                    <!-- 별점 -->
                    <?php $avg = $q['stats']['avg'] ?? 0; $dist = $q['stats']['distribution'] ?? []; ?>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="fs-2 fw-bold text-warning"><?= $avg ?></div>
                        <div>
                            <div class="text-warning">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="bi bi-star<?= $s <= round($avg) ? '-fill' : '' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <div class="text-muted small">평균 별점</div>
                        </div>
                    </div>
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                    <?php $cnt = $dist[$s] ?? 0; $pct = $totalResponses > 0 ? round($cnt / $totalResponses * 100, 1) : 0; ?>
                    <div class="mb-2">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="small" style="width:30px"><?= $s ?>점</span>
                            <div class="survey-bar-track flex-grow-1">
                                <div class="survey-bar-fill bg-warning" style="width:<?= $pct ?>%"></div>
                            </div>
                            <span class="text-muted small" style="width:60px text-end">
                                <?= $cnt ?>건 (<?= $pct ?>%)
                            </span>
                        </div>
                    </div>
                    <?php endfor; ?>

                <?php else: ?>
                    <!-- 텍스트형 -->
                    <?php if (empty($q['stats'])): ?>
                    <div class="text-muted small">응답 없음</div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($q['stats'] as $text): ?>
                        <li class="list-group-item py-1 small"><?= htmlspecialchars($text) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.survey-bar-track {
    background: var(--bs-secondary-bg);
    border-radius: 4px;
    height: 18px;
    overflow: hidden;
}
.survey-bar-fill {
    background: var(--bs-primary);
    height: 100%;
    border-radius: 4px;
    transition: width .4s ease;
    min-width: 2px;
}
</style>
