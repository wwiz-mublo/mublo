<?php
$claim = $claim ?? [];
$companies = $companies ?? [];
$statusOptions = $statusOptions ?? [];
$status = (string) ($claim['return_status'] ?? '');
$claimId = (int) ($claim['return_id'] ?? 0);
$shipments = $claim['shipments'] ?? [];
$logs = $claim['logs'] ?? [];
$roleLabels = ['COLLECTION' => '회수', 'EXCHANGE_OUTBOUND' => '교환 재출고', 'REJECTED_RETURN' => '고객 반송', 'ORIGINAL' => '최초 배송'];
$shipmentStatusLabels = ['READY' => '준비', 'PICKED_UP' => '집화', 'IN_TRANSIT' => '배송중', 'DELIVERED' => '배송완료', 'FAILED' => '실패'];
$claimType = (string) ($claim['return_type'] ?? 'EXCHANGE');
$isExchange = $claimType === 'EXCHANGE';
$typeLabel = $isExchange ? '교환' : '반품';
$statusLabel = \Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom($status)?->label($claimType) ?? $status;
// 완료·종결은 해당 역할 송장이 배송완료여야 서버가 받아준다(ClaimService::complete·closeRejected).
// 버튼만 열어두면 눌렀다 거절당하므로, 그 조건을 화면에서 미리 알려준다.
$deliveredRole = static function (string $role) use ($shipments): bool {
    foreach ($shipments as $sh) {
        if (($sh['shipment_role'] ?? '') === $role && ($sh['shipment_status'] ?? '') === 'DELIVERED') {
            return true;
        }
    }
    return false;
};
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= $typeLabel ?> #<?= $claimId ?></h3>
            <p>주문 <a href="/admin/shop/orders/<?= urlencode((string) ($claim['order_no'] ?? '')) ?>?activeCode=K_Shop_005"><?= htmlspecialchars($claim['order_no'] ?? '') ?></a></p>
        </div>
        <?php // 클레임은 늘 어떤 주문의 어떤 상품에 붙어 있다. 주문 상세에서 들어온 경우
              // '목록'만 있으면 왔던 곳으로 돌아갈 수 없어 막다른 길이 된다. ?>
        <div class="page-title-actions">
            <a href="/admin/shop/orders/<?= urlencode((string) ($claim['order_no'] ?? '')) ?>?activeCode=K_Shop_005" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-receipt"></i> 주문 상세
            </a>
            <a href="/admin/shop/claims?activeCode=K_Shop_016" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 반품·교환 목록
            </a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-hero">
                    <i class="bi bi-box-seam text-pastel-purple"></i>
                    <span><?= $typeLabel ?> 상품</span>
                </div>
                <div class="card-body">
                <div class="row">
                    <div class="col-md-5">
                        <div class="text-muted small">기존</div>
                        <strong><?= htmlspecialchars($claim['source_goods_name'] ?? '') ?></strong>
                        <div><?= htmlspecialchars($claim['source_option_name'] ?? '옵션 없음') ?></div>
                    </div>
                    <div class="col-md-2 text-center fs-3"><?= $isExchange ? '→' : '↩' ?></div>
                    <div class="col-md-5">
                        <?php if ($isExchange): ?>
                            <div class="text-muted small">교환</div>
                            <strong><?= htmlspecialchars($claim['source_goods_name'] ?? '') ?></strong>
                            <div><?= htmlspecialchars($claim['target_option_name'] ?? '동일 상품') ?> · <?= (int) ($claim['exchange_quantity'] ?? 0) ?>개</div>
                        <?php else: ?>
                            <div class="text-muted small">환불 예정</div>
                            <strong><?= number_format((int) ($claim['refund_amount'] ?? 0)) ?>원</strong>
                            <div><?= (int) ($claim['quantity'] ?? 0) ?>개 · 반품비 <?= number_format((int) ($claim['return_shipping_fee'] ?? 0)) ?>원 차감</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div></div>

            <div class="card mb-3">
                <div class="card-hero">
                    <i class="bi bi-clipboard-check text-pastel-blue"></i>
                    <span>사유 · 비용 · 회수지</span>
                </div>
                <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">사유</dt><dd class="col-sm-9">
                        <strong><?= htmlspecialchars(\Mublo\Packages\Shop\Enum\ClaimReason::labelFor($claim['reason_type'] ?? '')) ?></strong>
                        <?php if (trim((string) ($claim['reason_detail'] ?? '')) !== ''): ?>
                            <div class="text-muted small"><?= nl2br(htmlspecialchars((string) $claim['reason_detail'])) ?></div>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-sm-3">귀책</dt><dd class="col-sm-9"><?= htmlspecialchars($claim['responsibility'] ?? '') ?></dd>
                    <?php if ($isExchange): ?>
                    <dt class="col-sm-3">교환 배송비</dt><dd class="col-sm-9"><?= number_format((int) ($claim['exchange_shipping_fee'] ?? 0)) ?>원 · <?= htmlspecialchars($claim['fee_status'] ?? '') ?></dd>
                    <?php else: ?>
                    <dt class="col-sm-3">반품 배송비</dt><dd class="col-sm-9"><?= number_format((int) ($claim['return_shipping_fee'] ?? 0)) ?>원 (환불액에서 차감)</dd>
                    <dt class="col-sm-3">환불 예정액</dt><dd class="col-sm-9"><strong><?= number_format((int) ($claim['refund_amount'] ?? 0)) ?>원</strong></dd>
                    <?php endif; ?>
                    <dt class="col-sm-3">회수지</dt><dd class="col-sm-9">[<?= htmlspecialchars($claim['pickup_zipcode'] ?? '') ?>] <?= htmlspecialchars(($claim['pickup_address1'] ?? '') . ' ' . ($claim['pickup_address2'] ?? '')) ?><br><?= htmlspecialchars(($claim['pickup_name'] ?? '') . ' ' . ($claim['pickup_phone'] ?? '')) ?></dd>
                    <dt class="col-sm-3">검수</dt><dd class="col-sm-9"><?= htmlspecialchars($claim['inspection_result'] ?? 'PENDING') ?></dd>
                </dl>
            </div></div>

            <div class="card mb-3">
                <div class="card-hero">
                    <i class="bi bi-truck text-pastel-green"></i>
                    <span>배송</span>
                </div>
                <div class="card-body">
                <div class="table-responsive"><table class="table mb-0 align-middle">
                <thead><tr><th>구분</th><th>택배사</th><th>송장</th><th>상태</th><th>처리</th></tr></thead><tbody>
                <?php if ($shipments === []): ?><tr><td colspan="5" class="text-center text-muted">등록된 배송이 없습니다.</td></tr><?php endif; ?>
                <?php foreach ($shipments as $shipment): ?>
                    <tr><td><?= htmlspecialchars($roleLabels[$shipment['shipment_role'] ?? ''] ?? ($shipment['shipment_role'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($shipment['company_name'] ?? '-') ?></td>
                        <td><?php if (!empty($shipment['tracking_url'])): ?><a href="<?= htmlspecialchars($shipment['tracking_url']) ?>" target="_blank"><?= htmlspecialchars($shipment['invoice_no'] ?? '') ?></a><?php else: ?><?= htmlspecialchars($shipment['invoice_no'] ?? '') ?><?php endif; ?></td>
                        <?php $shStatus = (string) ($shipment['shipment_status'] ?? ''); ?>
                        <td><span class="badge bg-<?= $shStatus === 'DELIVERED' ? 'success' : ($shStatus === 'FAILED' ? 'danger' : 'info') ?>-subtle text-<?= $shStatus === 'DELIVERED' ? 'success' : ($shStatus === 'FAILED' ? 'danger' : 'info') ?>-emphasis border border-<?= $shStatus === 'DELIVERED' ? 'success' : ($shStatus === 'FAILED' ? 'danger' : 'info') ?>-subtle"><?= htmlspecialchars($shipmentStatusLabels[$shStatus] ?? $shStatus) ?></span></td>
                        <td class="text-nowrap">
                            <?php foreach (['READY' => 'PICKED_UP', 'PICKED_UP' => 'IN_TRANSIT', 'IN_TRANSIT' => 'DELIVERED'] as $from => $to): ?>
                                <?php if ($shStatus === $from): ?><button class="btn btn-sm btn-outline-primary js-shipment" data-id="<?= (int) $shipment['shipment_id'] ?>" data-status="<?= $to ?>"><?= htmlspecialchars($shipmentStatusLabels[$to] ?? $to) ?>로</button><?php endif; ?>
                            <?php endforeach; ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-ship-edit" data-id="<?= (int) $shipment['shipment_id'] ?>">수정</button>
                        </td>
                    </tr>
                    <?php // 잘못 넣은 택배사·송장번호를 고칠 길. 이 화면에 없으면 주문 화면도
                          // 클레임 송장은 거절하므로 아무도 못 고친다. ?>
                    <tr class="d-none" data-ship-edit="<?= (int) $shipment['shipment_id'] ?>">
                        <td colspan="5">
                            <form class="js-ship-update row g-2 align-items-center" data-id="<?= (int) $shipment['shipment_id'] ?>">
                                <div class="col-md-3">
                                    <select name="company_id" class="form-select form-select-sm" required>
                                        <option value="">택배사 선택</option>
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?= (int) $company['company_id'] ?>" <?= (int) ($shipment['company_id'] ?? 0) === (int) $company['company_id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'] ?? '') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input name="invoice_no" class="form-control form-control-sm" value="<?= htmlspecialchars($shipment['invoice_no'] ?? '') ?>" placeholder="송장번호" required>
                                </div>
                                <div class="col-md-4">
                                    <input name="admin_memo" class="form-control form-control-sm" value="<?= htmlspecialchars($shipment['admin_memo'] ?? '') ?>" placeholder="메모 (기타 선택 시 택배사명)">
                                </div>
                                <div class="col-md-2">
                                    <button class="btn btn-sm btn-primary w-100">저장</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                </div>
            </div>

            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-clock-history text-pastel-orange"></i>
                    <span>처리 이력</span>
                </div>
                <div class="card-body">
                <div class="table-responsive"><table class="table mb-0">
                <thead><tr><th>일시</th><th>처리자</th><th>상태</th><th>사유</th></tr></thead><tbody>
                <?php foreach ($logs as $log): ?><tr><td><?= htmlspecialchars($log['created_at'] ?? '') ?></td><td><?= htmlspecialchars($log['changed_by'] ?? '') ?></td><td><?= htmlspecialchars(($log['prev_status'] ?? '') . ' → ' . ($log['new_status'] ?? '')) ?></td><td><?= htmlspecialchars($log['reason'] ?? '') ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card sticky-top" style="top:16px">
                <div class="card-hero">
                    <i class="bi bi-arrow-repeat text-pastel-orange"></i>
                    <span>현재 상태</span>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-auto"><?= htmlspecialchars($statusLabel) ?></span>
                </div>
                <div class="card-body d-grid gap-2">
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
                    <button class="btn btn-primary js-inspect" data-action="inspect_approve"><?= $isExchange ? '검수 승인 · 재출고 대기' : '검수 승인 · 환불 대기' ?></button>
                    <button class="btn btn-outline-danger js-inspect" data-action="inspect_reject">검수 거절</button>
                    <div class="form-text">검수 거절은 회수품을 고객에게 반송합니다. 회수품 상태를 거절 사유에 맞게 고르고, 사유를 남겨주세요.</div>
                <?php elseif ($status === 'READY_TO_REFUND'): ?>
                    <?php $refundAmount = (int) ($claim['refund_amount'] ?? 0); ?>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">환불 예정액</span>
                        <strong class="fs-5"><?= number_format($refundAmount) ?>원</strong>
                    </div>
                    <?php if (($refundedForClaim ?? 0) > 0): ?>
                        <div class="alert alert-success py-2 px-3 mb-0 small">
                            이 반품 건으로 <strong><?= number_format((int) $refundedForClaim) ?>원</strong>이 이미 환불되었습니다.
                        </div>
                    <?php endif; ?>
                    <select id="refundMethod" class="form-select">
                        <option value="">환불 방법 선택</option>
                        <option value="PG_CANCEL">PG 결제 취소 (카드/간편결제)</option>
                        <option value="BANK">무통장 환불 (계좌 이체)</option>
                        <option value="POINT">포인트 환불</option>
                    </select>
                    <div id="refundBankArea" class="d-none d-grid gap-2">
                        <input type="text" id="refundBank" class="form-control" placeholder="은행명">
                        <input type="text" id="refundAccount" class="form-control" placeholder="계좌번호">
                        <input type="text" id="refundHolder" class="form-control" placeholder="예금주">
                    </div>
                    <button class="btn btn-success" id="btnClaimRefund">환불하고 반품 완료</button>
                    <div class="form-text">환불이 실제로 나간 뒤에만 반품이 종료됩니다. 이 환불은 이 반품 건에 기록됩니다.</div>
                    <hr class="my-1">
                    <button class="btn btn-outline-secondary btn-sm js-reason" data-action="refund_complete">환불 없이 완료로 표시</button>
                    <div class="form-text">밖에서 이미 환불했을 때만 사용하세요. 사유를 남겨야 합니다.</div>
                <?php elseif ($status === 'READY_TO_SHIP'): ?>
                    <?php $action = 'reship'; $label = '교환 상품 재출고'; include __DIR__ . '/_shipment_form.php'; ?>
                <?php elseif ($status === 'RESHIPPING'): ?>
                    <?php if ($deliveredRole('EXCHANGE_OUTBOUND')): ?>
                        <button class="btn btn-success js-action" data-action="complete">교환 완료</button>
                    <?php else: ?>
                        <button class="btn btn-success" disabled>교환 완료</button>
                        <div class="form-text">아래 <strong>배송</strong> 표에서 교환 재출고 송장을 <strong>배송완료</strong>로 옮기면 완료할 수 있습니다.</div>
                    <?php endif; ?>
                <?php elseif ($status === 'REJECTED'): ?>
                    <?php $action = 'return_rejected'; $label = '고객 반송'; include __DIR__ . '/_shipment_form.php'; ?>
                <?php elseif ($status === 'RETURNING'): ?>
                    <?php if ($deliveredRole('REJECTED_RETURN')): ?>
                        <button class="btn btn-secondary js-action" data-action="close">반송 완료·종결</button>
                    <?php else: ?>
                        <button class="btn btn-secondary" disabled>반송 완료·종결</button>
                        <div class="form-text">아래 <strong>배송</strong> 표에서 고객 반송 송장을 <strong>배송완료</strong>로 옮기면 종결할 수 있습니다.</div>
                    <?php endif; ?>
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
 const url='/admin/shop/claims/<?= (int) $claimId ?>/process';
 function send(data){return MubloRequest.requestJson(url,data).then(()=>location.reload());}
 document.querySelectorAll('.js-action').forEach(b=>b.addEventListener('click',()=>{if(confirm('처리하시겠습니까?'))send({action:b.dataset.action});}));
 document.querySelectorAll('.js-reason').forEach(b=>b.addEventListener('click',()=>{const r=prompt('처리 사유를 입력해주세요.');if(r!==null&&r.trim())send({action:b.dataset.action,reason:r.trim()});}));
 document.querySelectorAll('.js-inspect').forEach(b=>b.addEventListener('click',()=>{
   const result=document.getElementById('inspectionResult').value;
   // 서버도 같은 규칙으로 막지만(ClaimService::inspect), 왕복 전에 알려준다
   if(b.dataset.action==='inspect_reject'&&result==='SALEABLE'){
     MubloRequest.showAlert('검수 거절 시에는 정상 재판매를 선택할 수 없습니다. 회수품은 고객에게 반송됩니다.','error');return;
   }
   // prompt 취소는 null 이다. ?? '' 로 덮으면 취소와 '메모 없이 확인'이 구별되지 않아
   // 되돌릴 수 없는 검수가 그대로 실행된다.
   const rejecting=b.dataset.action==='inspect_reject';
   const r=prompt(rejecting?'거절 사유를 입력해주세요. (고객에게 반송됩니다)':'검수 메모 (선택)');
   if(r===null){ return; }
   if(rejecting&&r.trim()===''){ MubloRequest.showAlert('거절 사유를 입력해주세요.','warning'); return; }
   send({action:b.dataset.action,inspection_result:result,reason:r});
 }));
 document.querySelectorAll('.js-ship-form').forEach(f=>f.addEventListener('submit',e=>{e.preventDefault();send({action:f.dataset.action,company_id:f.querySelector('[name=company_id]').value,invoice_no:f.querySelector('[name=invoice_no]').value,admin_memo:f.querySelector('[name=admin_memo]').value});}));
 document.getElementById('refundMethod')?.addEventListener('change',function(){
   // 무통장 환불만 계좌 정보를 받는다
   document.getElementById('refundBankArea').classList.toggle('d-none', this.value!=='BANK');
 });
 document.getElementById('btnClaimRefund')?.addEventListener('click',()=>{
   const method=document.getElementById('refundMethod').value;
   if(!method){ MubloRequest.showAlert('환불 방법을 선택해주세요.','warning'); return; }
   MubloRequest.showConfirm('환불 예정액을 환불하고 반품을 완료합니다. 진행할까요?',function(){
     send({
       action:'refund', refund_method:method,
       refund_bank:document.getElementById('refundBank').value,
       refund_account:document.getElementById('refundAccount').value,
       refund_holder:document.getElementById('refundHolder').value
     });
   });
 });
 document.querySelectorAll('.js-ship-edit').forEach(b=>b.addEventListener('click',()=>{
   document.querySelector('[data-ship-edit="'+b.dataset.id+'"]')?.classList.toggle('d-none');
 }));
 document.querySelectorAll('.js-ship-update').forEach(f=>f.addEventListener('submit',e=>{
   e.preventDefault();
   send({action:'shipment_update',shipment_id:f.dataset.id,
     company_id:f.querySelector('[name=company_id]').value,
     invoice_no:f.querySelector('[name=invoice_no]').value,
     admin_memo:f.querySelector('[name=admin_memo]').value});
 }));
 document.querySelectorAll('.js-shipment').forEach(b=>b.addEventListener('click',()=>send({action:'shipment_status',shipment_id:b.dataset.id,shipment_status:b.dataset.status})));
 document.getElementById('feePaid')?.addEventListener('click',()=>send({action:'fee_paid',fee_method:document.getElementById('feeMethod').value}));
})();
</script>
