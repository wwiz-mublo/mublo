<?php
/**
 * 이노페이 결제 로그
 *
 * @var string $pageTitle
 * @var array  $logs
 * @var array  $filters
 * @var array  $steps
 * @var array  $needsAction
 * @var array  $pagination
 */
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$logs = $logs ?? [];
$filters = $filters ?? [];
$needsAction = $needsAction ?? [];

$prettyJson = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $decoded = json_decode($json, true);
    return $decoded === null
        ? $json
        : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

$levelBadge = static function (string $level): string {
    return match ($level) {
        'error' => 'text-danger-emphasis bg-danger-subtle border border-danger-subtle',
        'warning' => 'text-warning-emphasis bg-warning-subtle border border-warning-subtle',
        default => 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle',
    };
};

$filterLink = static fn(string $keyword) => '/admin/innopay/logs?keyword=' . rawurlencode($keyword);
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= $e($pageTitle) ?></h3>
            <p>결제창 호출부터 승인·주문 반영·취소까지 단계별로 기록합니다. 주문번호나 거래번호로 한 건의 흐름을 볼 수 있습니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/innopay/settings" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> 설정</a>
        </div>
    </div>

    <div class="page-block">
        <?php if (!empty($needsAction)): ?>
        <div class="alert alert-danger">
            <strong><i class="bi bi-exclamation-octagon"></i> 확인 필요 <?= count($needsAction) ?>건</strong>
            — 결제는 승인됐지만 주문 반영과 자동 취소가 모두 실패했습니다. 이노페이 상점관리에서 거래를 확인하고 환불하거나 주문을 직접 처리해주세요.
            <ul class="mb-0 mt-2">
                <?php foreach ($needsAction as $a): ?>
                <li>
                    <a href="<?= $e($filterLink((string) $a['moid'])) ?>"><code><?= $e($a['moid']) ?></code></a>
                    · TID <code><?= $e($a['tid'] ?? '-') ?></code>
                    · <?= number_format((int) $a['amount']) ?>원
                    · <?= $e($a['result_msg'] ?? '') ?>
                    · <?= $e($a['updated_at']) ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- 검색 -->
        <form method="get" action="/admin/innopay/logs" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
                <label class="form-label small mb-1">주문번호 / 거래번호</label>
                <input type="text" name="keyword" class="form-control form-control-sm" value="<?= $e($filters['keyword'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">단계</label>
                <select name="step" class="form-select form-select-sm">
                    <option value="">전체</option>
                    <?php foreach ($steps as $code => $label): ?>
                    <option value="<?= $e($code) ?>" <?= ($filters['step'] ?? '') === $code ? 'selected' : '' ?>><?= $e($label) ?> (<?= $e($code) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">수준</label>
                <select name="level" class="form-select form-select-sm">
                    <option value="">전체</option>
                    <?php foreach (['info' => '정보', 'warning' => '경고', 'error' => '오류'] as $code => $label): ?>
                    <option value="<?= $code ?>" <?= ($filters['level'] ?? '') === $code ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">날짜</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= $e($filters['date'] ?? '') ?>">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> 검색</button>
                <a href="/admin/innopay/logs" class="btn btn-sm btn-outline-secondary">초기화</a>
            </div>
        </form>

        <div class="mb-2 small text-muted">총 <b><?= number_format((int) ($pagination['totalItems'] ?? 0)) ?></b>건</div>

        <div class="table-responsive">
            <table class="table table-hover align-middle small">
                <thead>
                    <tr>
                        <th style="width:150px">일시</th>
                        <th style="width:150px">단계</th>
                        <th>주문번호 / 거래번호</th>
                        <th style="width:90px" class="text-end">금액</th>
                        <th style="width:110px">결과코드</th>
                        <th>메시지</th>
                        <th style="width:80px" class="text-end">소요</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">기록이 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $request = $prettyJson($log['request'] ?? null);
                    $response = $prettyJson($log['response'] ?? null);
                    $step = (string) $log['step'];
                    ?>
                    <tr>
                        <td class="text-nowrap"><?= $e(substr((string) $log['created_at'], 0, 19)) ?></td>
                        <td>
                            <span class="badge <?= $levelBadge((string) $log['level']) ?>"><?= $e($step) ?></span>
                            <div class="text-muted" style="font-size:.75rem"><?= $e($steps[$step] ?? '') ?></div>
                        </td>
                        <td>
                            <?php if ($log['moid'] !== ''): ?>
                            <a href="<?= $e($filterLink((string) $log['moid'])) ?>"><code><?= $e($log['moid']) ?></code></a>
                            <?php endif; ?>
                            <?php if ($log['tid'] !== ''): ?>
                            <div><a href="<?= $e($filterLink((string) $log['tid'])) ?>" class="text-muted"><code><?= $e($log['tid']) ?></code></a></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= $log['amount'] !== null ? number_format((int) $log['amount']) : '' ?></td>
                        <td><code><?= $e($log['result_code'] ?? '') ?></code><?= $log['http_status'] !== null ? ' <span class="text-muted">HTTP ' . (int) $log['http_status'] . '</span>' : '' ?></td>
                        <td>
                            <?= $e($log['result_msg'] ?? '') ?>
                            <?php if ($request !== '' || $response !== '' || !empty($log['ip'])): ?>
                            <details class="mt-1">
                                <summary class="text-primary" style="cursor:pointer">상세</summary>
                                <?php if (!empty($log['ip'])): ?>
                                <div class="text-muted mt-1">IP <?= $e($log['ip']) ?> · <?= $e($log['user_agent'] ?? '') ?></div>
                                <?php endif; ?>
                                <?php if ($request !== ''): ?>
                                <div class="fw-semibold mt-2">요청</div>
                                <pre class="bg-body-tertiary p-2 mb-0" style="max-height:300px; overflow:auto; white-space:pre-wrap"><?= $e($request) ?></pre>
                                <?php endif; ?>
                                <?php if ($response !== ''): ?>
                                <div class="fw-semibold mt-2">응답</div>
                                <pre class="bg-body-tertiary p-2 mb-0" style="max-height:300px; overflow:auto; white-space:pre-wrap"><?= $e($response) ?></pre>
                                <?php endif; ?>
                            </details>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-muted"><?= $log['duration_ms'] !== null ? number_format((int) $log['duration_ms']) . 'ms' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (($pagination['totalPages'] ?? 1) > 1): ?>
        <div class="d-flex justify-content-end my-2">
            <?= $this->pagination($pagination) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
