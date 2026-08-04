<?php
$claim = $claim ?? [];
$companies = $companies ?? [];
$statusOptions = $statusOptions ?? [];
$status = (string) ($claim['return_status'] ?? '');
$claimId = (int) ($claim['return_id'] ?? 0);
$shipments = $claim['shipments'] ?? [];
$logs = $claim['logs'] ?? [];
$roleLabels = ['COLLECTION' => '회수', 'EXCHANGE_OUTBOUND' => '교환 재출고', 'REJECTED_RETURN' => '고객 반송', 'ORIGINAL' => '최초 배송'];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3>교환 #<?= $claimId ?></h3>
            <p>주문 <a href="/admin/shop/orders/<?= urlencode((string) ($claim['order_no'] ?? '')) ?>"><?= htmlspecialchars($claim['order_no'] ?? '') ?></a></p>
        </div>
        <div class="page-title-actions"><a href="/admin/shop/exchanges" class="btn btn-sm btn-outline-secondary">목록</a></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3"><div class="card-header fw-semibold">교환 상품</div><div class="card-body">
                <div class="row">
                    <div class="col-md-5">
                        <div class="text-muted small">기존</div>
                        <strong><?= htmlspecialchars($claim['source_goods_name'] ?? '') ?></strong>
                        <div><?= htmlspecialchars($claim['source_option_name'] ?? '옵션 없음') ?></div>
                    </div>
                    <div class="col-md-2 text-center fs-3">→</div>
                    <div class="col-md-5">
                        <div class="text-muted small">교환</div>
                        <strong><?= htmlspecialchars($claim['source_goods_name'] ?? '') ?></strong>
                        <div><?= htmlspecialchars($claim['target_option_name'] ?? '동일 상품') ?> · <?= (int) ($claim['exchange_quantity'] ?? 0) ?>개</div>
                    </div>
                </div>
            </div></div>

            <div class="card mb-3"><div class="card-header fw-semibold">사유·비용·회수지</div><div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">사유</dt><dd class="col-sm-9"><?= htmlspecialchars(($claim['reason_type'] ?? '') . ' ' . ($claim['reason_detail'] ?? '')) ?></dd>
                    <dt class="col-sm-3">귀책</dt><dd class="col-sm-9"><?= htmlspecialchars($claim['responsibility'] ?? '') ?></dd>
                    <dt class="col-sm-3">교환 배송비</dt><dd class="col-sm-9"><?= number_format((int) ($claim['exchange_shipping_fee'] ?? 0)) ?>원 · <?= htmlspecialchars($claim['fee_status'] ?? '') ?></dd>
                    <dt class="col-sm-3">회수지</dt><dd class="col-sm-9">[<?= htmlspecialchars($claim['pickup_zipcode'] ?? '') ?>] <?= htmlspecialchars(($claim['pickup_address1'] ?? '') . ' ' . ($claim['pickup_address2'] ?? '')) ?><br><?= htmlspecialchars(($claim['pickup_name'] ?? '') . ' ' . ($claim['pickup_phone'] ?? '')) ?></dd>
                    <dt class="col-sm-3">검수</dt><dd class="col-sm-9"><?= htmlspecialchars($claim['inspection_result'] ?? 'PENDING') ?></dd>
                </dl>
            </div></div>

            <div class="card mb-3"><div class="card-header fw-semibold">배송</div><div class="table-responsive"><table class="table mb-0 align-middle">
                <thead><tr><th>구분</th><th>택배사</th><th>송장</th><th>상태</th><th>처리</th></tr></thead><tbody>
                <?php if ($shipments === []): ?><tr><td colspan="5" class="text-center text-muted">등록된 배송이 없습니다.</td></tr><?php endif; ?>
                <?php foreach ($shipments as $shipment): ?>
                    <tr><td><?= htmlspecialchars($roleLabels[$shipment['shipment_role'] ?? ''] ?? ($shipment['shipment_role'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($shipment['company_name'] ?? '-') ?></td>
                        <td><?php if (!empty($shipment['tracking_url'])): ?><a href="<?= htmlspecialchars($shipment['tracking_url']) ?>" target="_blank"><?= htmlspecialchars($shipment['invoice_no'] ?? '') ?></a><?php else: ?><?= htmlspecialchars($shipment['invoice_no'] ?? '') ?><?php endif; ?></td>
                        <td><?= htmlspecialchars($shipment['shipment_status'] ?? '') ?></td>
                        <td>
                            <?php foreach (['READY' => 'PICKED_UP', 'PICKED_UP' => 'IN_TRANSIT', 'IN_TRANSIT' => 'DELIVERED'] as $from => $to): ?>
                                <?php if (($shipment['shipment_status'] ?? '') === $from): ?><button class="btn btn-sm btn-outline-primary js-shipment" data-id="<?= (int) $shipment['shipment_id'] ?>" data-status="<?= $to ?>"><?= $to ?></button><?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div></div>

            <div class="card"><div class="card-header fw-semibold">처리 이력</div><div class="table-responsive"><table class="table mb-0">
                <thead><tr><th>일시</th><th>처리자</th><th>상태</th><th>사유</th></tr></thead><tbody>
                <?php foreach ($logs as $log): ?><tr><td><?= htmlspecialchars($log['created_at'] ?? '') ?></td><td><?= htmlspecialchars($log['changed_by'] ?? '') ?></td><td><?= htmlspecialchars(($log['prev_status'] ?? '') . ' → ' . ($log['new_status'] ?? '')) ?></td><td><?= htmlspecialchars($log['reason'] ?? '') ?></td></tr><?php endforeach; ?>
                </tbody></table></div></div>
        </div>

        <div class="col-lg-4">
            <div class="card sticky-top" style="top:16px"><div class="card-header fw-semibold">현재 상태: <?= htmlspecialchars($statusOptions[$status] ?? $status) ?></div><div class="card-body d-grid gap-2">
                <?php if ($status === 'REQUESTED'): ?>
                    <button class="btn btn-primary js-action" data-action="accept">교환 승인</button>
                    <button class="btn btn-outline-danger js-reason" data-action="refuse">신청 거절</button>
                <?php elseif ($status === 'ACCEPTED'): ?>
                    <?php $action = 'collect'; $label = '회수 송장 등록'; include __DIR__ . '/_shipment_form.php'; ?>
                <?php elseif ($status === 'COLLECTING'): ?>
                    <button class="btn btn-primary js-action" data-action="collected">회수 완료</button>
                <?php elseif ($status === 'COLLECTED'): ?>
                    <button class="btn btn-primary js-action" data-action="inspect_start">검수 시작</button>
                <?php elseif ($status === 'INSPECTING'): ?>
                    <?php /* 회수품 상태. '정상 재판매' 는 승인 건에서만 쓴다 — 거절 건의
                             회수품은 고객에게 반송되므로 판매 재고로 잡으면 안 된다. */ ?>
                    <select id="inspectionResult" class="form-select"><option value="SALEABLE">정상 재판매</option><option value="DEFECTIVE">불량</option><option value="DISCARD">폐기</option><option value="WRONG_ITEM">오배송품</option></select>
                    <button class="btn btn-primary js-inspect" data-action="inspect_approve">교환 승인·재출고 대기</button>
                    <button class="btn btn-outline-danger js-inspect" data-action="inspect_reject">검수 거절</button>
                    <div class="form-text">검수 거절은 회수품을 고객에게 반송합니다. 거절 사유에 맞는 회수품 상태를 골라주세요.</div>
                <?php elseif ($status === 'READY_TO_SHIP'): ?>
                    <?php $action = 'reship'; $label = '교환 상품 재출고'; include __DIR__ . '/_shipment_form.php'; ?>
                <?php elseif ($status === 'RESHIPPING'): ?>
                    <button class="btn btn-success js-action" data-action="complete">교환 완료</button>
                <?php elseif ($status === 'REJECTED'): ?>
                    <?php $action = 'return_rejected'; $label = '고객 반송'; include __DIR__ . '/_shipment_form.php'; ?>
                <?php elseif ($status === 'RETURNING'): ?>
                    <button class="btn btn-secondary js-action" data-action="close">반송 완료·종결</button>
                <?php endif; ?>

                <?php if (($claim['fee_status'] ?? '') === 'UNPAID'): ?>
                    <hr><select id="feeMethod" class="form-select"><option value="BANK">계좌입금</option><option value="COD">착불/현장</option><option value="MANUAL">수동확인</option></select>
                    <button class="btn btn-outline-primary" id="feePaid">교환비 납부 확인</button>
                <?php endif; ?>
                <?php if (in_array($status, ['REQUESTED', 'ACCEPTED'], true)): ?>
                    <hr><button class="btn btn-outline-secondary js-reason" data-action="cancel">교환 취소</button>
                <?php endif; ?>
            </div></div>
        </div>
    </div>
</div>
<script>
(function(){
 const url='/admin/shop/exchanges/<?= (int) $claimId ?>/process';
 function send(data){return MubloRequest.requestJson(url,data).then(()=>location.reload());}
 document.querySelectorAll('.js-action').forEach(b=>b.addEventListener('click',()=>{if(confirm('처리하시겠습니까?'))send({action:b.dataset.action});}));
 document.querySelectorAll('.js-reason').forEach(b=>b.addEventListener('click',()=>{const r=prompt('처리 사유를 입력해주세요.');if(r!==null&&r.trim())send({action:b.dataset.action,reason:r.trim()});}));
 document.querySelectorAll('.js-inspect').forEach(b=>b.addEventListener('click',()=>{
   const result=document.getElementById('inspectionResult').value;
   // 서버도 같은 규칙으로 막지만(ExchangeService::inspect), 왕복 전에 알려준다
   if(b.dataset.action==='inspect_reject'&&result==='SALEABLE'){
     MubloRequest.showAlert('검수 거절 시에는 정상 재판매를 선택할 수 없습니다. 회수품은 고객에게 반송됩니다.','error');return;
   }
   const r=prompt('검수 메모 (선택)')??'';send({action:b.dataset.action,inspection_result:result,reason:r});
 }));
 document.querySelectorAll('.js-ship-form').forEach(f=>f.addEventListener('submit',e=>{e.preventDefault();send({action:f.dataset.action,company_id:f.querySelector('[name=company_id]').value,invoice_no:f.querySelector('[name=invoice_no]').value,admin_memo:f.querySelector('[name=admin_memo]').value});}));
 document.querySelectorAll('.js-shipment').forEach(b=>b.addEventListener('click',()=>send({action:'shipment_status',shipment_id:b.dataset.id,shipment_status:b.dataset.status})));
 document.getElementById('feePaid')?.addEventListener('click',()=>send({action:'fee_paid',fee_method:document.getElementById('feeMethod').value}));
})();
</script>
