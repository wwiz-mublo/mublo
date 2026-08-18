<?php
/** @var array $claims */
$claims = $claims ?? [];
$filters = $filters ?? [];
$pagination = $pagination ?? [];
$statusOptions = $statusOptions ?? [];
$claimActions = $claimActions ?? [];
$notificationChannels = $notificationChannels ?? [];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '교환·반품 관리') ?></h3>
            <p>교환과 반품은 회수·검수까지 같은 길을 걷고 마지막에만 갈립니다 — 교환은 재출고로, 반품은 환불로 끝납니다.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center">
            <div><strong>클레임 상태 Action</strong><div class="small text-muted">주문 Action과 분리되며 알림·웹훅만 실행합니다.</div></div>
            <button type="button" class="btn btn-sm btn-primary ms-auto" id="saveClaimActions">Action 저장</button>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>상태</th><th>알림 채널</th><th>템플릿 코드</th><th>웹훅 URL</th><th>웹훅 서명키</th></tr></thead>
            <tbody>
            <?php foreach ($statusOptions as $statusValue => $statusLabel):
                $configured = is_array($claimActions[$statusValue] ?? null) ? $claimActions[$statusValue] : [];
                $notification = [];
                $webhook = [];
                foreach ($configured as $action) {
                    if (($action['type'] ?? '') === 'notification') { $notification = $action; }
                    if (($action['type'] ?? '') === 'webhook') { $webhook = $action; }
                }
            ?>
                <tr data-claim-action-status="<?= htmlspecialchars($statusValue) ?>">
                    <td class="text-nowrap"><?= htmlspecialchars($statusLabel) ?></td>
                    <td><select class="form-select form-select-sm js-claim-channel">
                        <option value="">사용 안 함</option>
                        <?php foreach ($notificationChannels as $channel => $label): ?>
                            <option value="<?= htmlspecialchars((string) $channel) ?>" <?= ($notification['channel'] ?? '') === $channel ? 'selected' : '' ?>><?= htmlspecialchars((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select></td>
                    <td><input class="form-control form-control-sm js-claim-template" value="<?= htmlspecialchars((string) ($notification['template_code'] ?? '')) ?>" placeholder="exchange_requested"></td>
                    <td><input type="url" class="form-control form-control-sm js-claim-webhook" value="<?= htmlspecialchars((string) ($webhook['url'] ?? '')) ?>" placeholder="https://..."></td>
                    <td><input type="password" class="form-control form-control-sm js-claim-secret" value="" placeholder="<?= !empty($webhook['secret']) ? '저장됨 · 변경 시만 입력' : '선택' ?>" autocomplete="new-password"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">유형</label>
                    <select name="return_type" class="form-select">
                        <option value="">전체</option>
                        <option value="EXCHANGE" <?= ($filters['return_type'] ?? '') === 'EXCHANGE' ? 'selected' : '' ?>>교환</option>
                        <option value="RETURN" <?= ($filters['return_type'] ?? '') === 'RETURN' ? 'selected' : '' ?>>반품</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">상태</label>
                    <select name="status" class="form-select">
                        <option value="">전체</option>
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">주문번호·상품명</label>
                    <input type="search" name="keyword" class="form-control" value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100">검색</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>번호</th><th>유형</th><th>주문번호</th><th>상품</th><th>교환 옵션</th>
                    <th class="text-center">수량</th><th>상태</th><th>귀책</th><th>신청일</th>
                </tr></thead>
                <tbody>
                <?php if ($claims === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">교환·반품 내역이 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($claims as $claim): ?>
                    <?php
                    $status = (string) ($claim['return_status'] ?? '');
                    $badge = in_array($status, ['COMPLETED', 'CLOSED'], true) ? 'success'
                        : (in_array($status, ['REFUSED', 'REJECTED', 'CANCELLED'], true) ? 'danger' : 'warning');
                    $resp = match ($claim['responsibility'] ?? '') { 'CUSTOMER' => '고객', 'SELLER' => '판매자', default => '관리자' };
                    $claimType = (string) ($claim['return_type'] ?? 'EXCHANGE');
                    $typeLabel = $claimType === 'RETURN' ? '반품' : '교환';
                    ?>
                    <tr>
                        <td><a href="/admin/shop/claims/<?= (int) $claim['return_id'] ?>">#<?= (int) $claim['return_id'] ?></a></td>
                        <td><span class="badge bg-<?= $claimType === 'RETURN' ? 'secondary' : 'info' ?>-subtle text-<?= $claimType === 'RETURN' ? 'secondary' : 'info' ?>-emphasis border border-<?= $claimType === 'RETURN' ? 'secondary' : 'info' ?>-subtle"><?= $typeLabel ?></span></td>
                        <td><a href="/admin/shop/orders/<?= urlencode((string) $claim['order_no']) ?>"><?= htmlspecialchars($claim['order_no'] ?? '') ?></a></td>
                        <td>
                            <div><?= htmlspecialchars($claim['source_goods_name'] ?? '') ?></div>
                            <small class="text-muted"><?= htmlspecialchars($claim['source_option_name'] ?? '-') ?></small>
                        </td>
                        <td><?= $claimType === 'RETURN' ? '<span class="text-muted">-</span>' : htmlspecialchars($claim['target_option_name'] ?? '동일 상품') ?></td>
                        <td class="text-center"><?= (int) ($claim['exchange_quantity'] ?? $claim['quantity'] ?? 0) ?></td>
                        <td><span class="badge bg-<?= $badge ?>-subtle text-<?= $badge ?>-emphasis border border-<?= $badge ?>-subtle"><?= htmlspecialchars(\Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom($status)?->label($claimType) ?? $status) ?></span></td>
                        <td><?= $resp ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars($claim['requested_at'] ?? $claim['created_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (($pagination['last_page'] ?? 1) > 1): ?>
        <nav class="mt-3"><ul class="pagination justify-content-center">
            <?php for ($page = 1; $page <= (int) $pagination['last_page']; $page++): ?>
                <li class="page-item <?= $page === (int) $pagination['current_page'] ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_filter(['page' => $page, 'return_type' => $filters['return_type'] ?? '', 'status' => $filters['status'] ?? '', 'keyword' => $filters['keyword'] ?? ''])) ?>"><?= $page ?></a>
                </li>
            <?php endfor; ?>
        </ul></nav>
    <?php endif; ?>
</div>
<script>
(function(){
 document.getElementById('saveClaimActions')?.addEventListener('click',()=>{
   const actions={};
   document.querySelectorAll('[data-claim-action-status]').forEach(row=>{
     const status=row.dataset.claimActionStatus, list=[];
     const channel=row.querySelector('.js-claim-channel').value.trim();
     const template=row.querySelector('.js-claim-template').value.trim();
     const url=row.querySelector('.js-claim-webhook').value.trim();
     const secret=row.querySelector('.js-claim-secret').value;
     if(channel&&template) list.push({type:'notification',action_id:'claim-'+status.toLowerCase()+'-notification',enabled:true,channel:channel,template_code:template,recipient:'recipient'});
     if(url){const webhook={type:'webhook',action_id:'claim-'+status.toLowerCase()+'-webhook',enabled:true,url:url,method:'POST'};if(secret)webhook.secret=secret;list.push(webhook);}
     if(list.length) actions[status]=list;
   });
   MubloRequest.requestJson('/admin/shop/claims/actions',{actions:actions}).then(()=>location.reload());
 });
})();
</script>
