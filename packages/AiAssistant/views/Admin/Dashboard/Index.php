<?php
/** @var array<string, mixed> $principal */
/** @var array<string, int> $summary */
/** @var list<array<string, mixed>> $batches */
/** @var list<array<string, mixed>> $customers */
/** @var list<array<string, mixed>> $workers */
$statusLabel = [
    'COMPLETED' => '분석 완료', 'INSUFFICIENT_DATA' => '데이터 부족', 'ACTION_REQUIRED' => '확인 필요',
    'FAILED_FINAL' => '최종 실패', 'IN_PROGRESS' => '진행 중', 'QUEUED' => '대기 중', 'LEASED' => '처리 중',
];
$statusClass = static fn(string $status): string => match ($status) {
    'COMPLETED' => 'success', 'INSUFFICIENT_DATA' => 'secondary',
    'ACTION_REQUIRED', 'FAILED_FINAL' => 'danger', default => 'primary',
};
$shortId = static fn(string $id): string => substr($id, 0, 8);
?>
<style>
.ai-hero{background:linear-gradient(135deg,#172554,#312e81 55%,#0f766e);border-radius:24px;color:#fff;padding:28px;box-shadow:0 18px 46px rgba(30,41,59,.16)}
.ai-kicker{font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;opacity:.7}.ai-metric{border:0;border-radius:18px;box-shadow:0 8px 28px rgba(15,23,42,.06)}
.ai-metric strong{font-size:1.8rem}.ai-panel{border:0;border-radius:20px;box-shadow:0 8px 28px rgba(15,23,42,.06)}
.ai-dot{width:9px;height:9px;border-radius:50%;display:inline-block;background:#22c55e}.ai-dot.offline{background:#ef4444}.ai-id{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
</style>
<div class="page-container">
  <section class="ai-hero mb-4" aria-labelledby="ai-dashboard-title">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-end">
      <div><div class="ai-kicker">Customer intelligence operations</div><h2 id="ai-dashboard-title" class="mt-2 mb-2">고객을 이해하는 일이 어디까지 왔는지</h2><p class="mb-0 opacity-75">수집·분석·운영 상태를 실제 서버 데이터로 확인합니다.</p></div>
      <div class="text-lg-end"><div class="small opacity-75">현재 회사</div><strong><?= htmlspecialchars((string) $principal['company_name']) ?></strong><div class="small mt-1"><?= htmlspecialchars((string) $principal['nickname']) ?> · <?= htmlspecialchars((string) $principal['role']) ?></div></div>
    </div>
  </section>

  <div class="row g-3 mb-4">
    <?php foreach ([
      ['관리 고객',$summary['customers'],'bi-people','text-primary'],
      ['분석 진행 중',$summary['analysis_active'],'bi-stars','text-info'],
      ['분석 완료',$summary['analysis_completed'],'bi-check2-circle','text-success'],
      ['확인 필요',$summary['analysis_action_required'],'bi-exclamation-diamond','text-danger'],
    ] as [$label,$value,$icon,$color]): ?>
      <div class="col-6 col-xl-3"><div class="card ai-metric h-100"><div class="card-body d-flex align-items-center gap-3"><i class="bi <?= $icon ?> fs-3 <?= $color ?>"></i><div><div class="text-muted small"><?= $label ?></div><strong><?= number_format((int) $value) ?></strong><span class="text-muted ms-1">건</span></div></div></div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-4">
    <div class="col-xl-7"><section class="card ai-panel h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-3"><div><h5 class="mb-1">최근 분석 작업</h5><div class="text-muted small">앱과 Worker의 실제 상태만 표시합니다.</div></div><span class="badge text-bg-light"><?= count($batches) ?>개</span></div>
      <?php if ($batches === []): ?><div class="py-5 text-center text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Android 앱에서 분석을 시작하면 여기에 표시됩니다.</div>
      <?php else: ?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>배치</th><th>목적</th><th>진행</th><th>상태</th><th>갱신</th></tr></thead><tbody>
      <?php foreach ($batches as $batch): $state=(string)$batch['display_status']; ?><tr><td class="ai-id"><?= htmlspecialchars($shortId((string)$batch['batch_id'])) ?></td><td><?= htmlspecialchars((string)$batch['purpose']) ?></td><td><?= (int)$batch['terminal_count'] ?>/<?= (int)$batch['run_count'] ?></td><td><span class="badge text-bg-<?= $statusClass($state) ?>"><?= htmlspecialchars($statusLabel[$state] ?? $state) ?></span></td><td class="text-muted small"><?= htmlspecialchars((string)$batch['updated_at']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div></section></div>

    <div class="col-xl-5"><section class="card ai-panel h-100"><div class="card-body"><h5 class="mb-1">시스템 상태</h5><div class="text-muted small mb-3">기기와 분석 Worker의 최근 heartbeat</div>
      <div class="d-flex justify-content-between py-2 border-bottom"><span>활성 기기</span><strong><?= $summary['active_devices'] ?>/<?= $summary['devices'] ?></strong></div>
      <?php if ($workers === []): ?><div class="alert alert-warning mt-3 mb-0"><i class="bi bi-exclamation-triangle me-2"></i>등록된 Worker heartbeat가 없습니다.</div>
      <?php else: foreach ($workers as $worker): $online=strtotime((string)$worker['received_at'].' UTC') >= time()-180; ?><div class="d-flex justify-content-between align-items-center py-3 border-bottom"><div><span class="ai-dot <?= $online?'':'offline' ?> me-2"></span><strong><?= htmlspecialchars((string)$worker['worker_id']) ?></strong><div class="small text-muted ms-3">v<?= htmlspecialchars((string)$worker['version']) ?> · active <?= (int)$worker['active_job_count'] ?></div></div><span class="badge text-bg-<?= $online?'success':'danger' ?>"><?= $online?'온라인':'오프라인' ?></span></div><?php endforeach; endif; ?>
    </div></section></div>
  </div>

  <section class="card ai-panel mt-4"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-3"><div><h5 class="mb-1">최근 고객</h5><div class="text-muted small">대화 원문은 노출하지 않고 분석 상태만 보여줍니다.</div></div><span class="badge text-bg-light">데이터 부족 <?= $summary['analysis_insufficient'] ?>건</span></div>
    <?php if ($customers === []): ?><div class="py-5 text-center text-muted">관리 중인 고객이 없습니다.</div><?php else: ?><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>고객</th><th>관리 상태</th><th>AI 분석</th><th>마지막 갱신</th></tr></thead><tbody>
    <?php foreach ($customers as $customer): $state=(string)($customer['analysis_status']??''); ?><tr><td><strong><?= htmlspecialchars((string)$customer['display_name']) ?></strong><div class="small text-muted ai-id"><?= htmlspecialchars($shortId((string)$customer['customer_id'])) ?></div></td><td><?= htmlspecialchars((string)$customer['management_status']) ?></td><td><?php if($state===''):?><span class="text-muted">분석 없음</span><?php else:?><span class="badge text-bg-<?= $statusClass($state) ?>"><?= htmlspecialchars($statusLabel[$state]??$state) ?></span><?php endif;?></td><td class="text-muted small"><?= htmlspecialchars((string)($customer['analysis_updated_at']??$customer['updated_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></section>
</div>
