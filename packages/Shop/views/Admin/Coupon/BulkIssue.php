<?php
/**
 * 쿠폰 일괄 지급 — 등급·가입일·구매 이력으로 대상을 골라 한꺼번에 발행
 *
 * @var string $pageTitle
 * @var array  $coupon     쿠폰 정책 (CouponPolicy::toArray)
 * @var array  $levels     일반 회원 등급 [level_value => 이름]
 * @var array  $summary    발급 요약 (total, used, expired, available)
 * @var int    $maxTargets 한 번에 지급하는 최대 인원
 */
$couponId = (int) ($coupon['coupon_group_id'] ?? 0);
$isActive = !empty($coupon['is_active']);
$discount = ($coupon['discount_type'] ?? '') === 'PERCENTAGE'
    ? (float) ($coupon['discount_value'] ?? 0) . '%'
    : number_format((int) ($coupon['discount_value'] ?? 0)) . '원';
?>
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>조건에 맞는 회원에게 이 쿠폰을 한꺼번에 발행합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/coupons" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list"></i> 목록</a>
            <a href="/admin/shop/coupons/<?= $couponId ?>/issued" class="btn btn-sm btn-default">발급 내역</a>
        </div>
    </div>

    <div class="page-block row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-people text-pastel-blue"></i>
                    <span>지급 대상</span>
                </div>
                <div class="card-body" id="bulk-issue-form">
                    <div class="mb-3">
                        <label class="form-label d-block">회원 등급 <small class="text-muted">(고르지 않으면 모든 일반 등급)</small></label>
                        <?php foreach ($levels as $value => $name): ?>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="lv<?= (int) $value ?>" name="level_values" value="<?= (int) $value ?>">
                            <label class="form-check-label" for="lv<?= (int) $value ?>">Lv<?= (int) $value ?> <?= htmlspecialchars($name) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="row mb-3">
                        <label class="form-label">가입일</label>
                        <div class="col-6 col-md-4"><input type="date" class="form-control" name="joined_from" aria-label="가입일 시작"></div>
                        <div class="col-6 col-md-4"><input type="date" class="form-control" name="joined_to" aria-label="가입일 끝"></div>
                        <div class="form-text">비워 두면 기간 제한 없음 (시작·끝 날짜 포함)</div>
                    </div>
                    <div class="row mb-3 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="purchase">쇼핑몰 구매 이력</label>
                            <select class="form-select" name="purchase" id="purchase">
                                <option value="">상관없음</option>
                                <option value="yes">구매한 회원</option>
                                <option value="no">구매하지 않은 회원</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4" id="min-purchase-box" hidden>
                            <label class="form-label" for="min_purchase">누적 구매액 이상</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="min_purchase" id="min_purchase" min="0" step="1000" value="0">
                                <span class="input-group-text">원</span>
                            </div>
                        </div>
                        <div class="form-text">결제 완료 이후 상태의 가전몰 주문 기준입니다. 휴대폰·렌탈 신청은 포함하지 않습니다.</div>
                    </div>
                    <p class="small text-muted mb-3">정상 회원만 대상이며 관리자 등급은 제외합니다. 이 쿠폰을 이미 회원별 한도만큼 받은 회원은 건너뜁니다.</p>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-default" id="btn-preview"><i class="bi bi-search"></i> 대상 인원 확인</button>
                        <button type="button" class="btn btn-primary" id="btn-issue" <?= $isActive ? '' : 'disabled' ?>><i class="bi bi-send"></i> 지급하기</button>
                        <span class="fw-semibold" id="preview-result"></span>
                    </div>
                    <?php if (!$isActive): ?>
                    <div class="alert alert-warning mt-3 mb-0">이 쿠폰은 비활성 상태라 지급할 수 없습니다. 쿠폰 수정에서 활성으로 바꿔 주세요.</div>
                    <?php endif; ?>
                    <div class="form-text">한 번에 최대 <?= number_format($maxTargets) ?>명까지 지급합니다.</div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-ticket-perforated text-pastel-purple"></i>
                    <span>쿠폰</span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5">쿠폰명</dt><dd class="col-7"><?= htmlspecialchars($coupon['name'] ?? '') ?></dd>
                        <dt class="col-5">할인</dt><dd class="col-7"><?= $discount ?></dd>
                        <dt class="col-5">회원별 한도</dt><dd class="col-7"><?= (int) ($coupon['download_limit_per_member'] ?? 1) ?>장</dd>
                        <dt class="col-5">총 발행 한도</dt><dd class="col-7"><?= $coupon['total_issue_limit'] !== null ? number_format((int) $coupon['total_issue_limit']) . '장' : '없음' ?></dd>
                        <dt class="col-5">발행 기간</dt><dd class="col-7"><?= htmlspecialchars(($coupon['issue_start'] ?? '') . ' ~ ' . ($coupon['issue_end'] ?? '')) ?></dd>
                        <dt class="col-5">발급 현황</dt>
                        <dd class="col-7 mb-0">
                            <?= number_format($summary['total']) ?>장
                            (사용 <?= number_format($summary['used']) ?> · 만료 <?= number_format($summary['expired']) ?>)
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('bulk-issue-form');
    var result = document.getElementById('preview-result');
    var purchase = document.getElementById('purchase');
    var base = '/admin/shop/coupons/<?= $couponId ?>/bulk-issue';

    var pendingCriteria = null;

    purchase.addEventListener('change', function () {
        document.getElementById('min-purchase-box').hidden = purchase.value !== 'yes';
    });

    function criteria() {
        if (pendingCriteria) return Object.assign({}, pendingCriteria);
        return {
            level_values: Array.prototype.map.call(box.querySelectorAll('input[name="level_values"]:checked'), function (el) { return parseInt(el.value, 10); }),
            joined_from: box.querySelector('[name="joined_from"]').value,
            joined_to: box.querySelector('[name="joined_to"]').value,
            purchase: purchase.value,
            min_purchase: purchase.value === 'yes' ? (parseInt(box.querySelector('[name="min_purchase"]').value, 10) || 0) : 0
        };
    }

    document.getElementById('btn-preview').addEventListener('click', function () {
        MubloRequest.requestJson(base + '/preview', criteria(), { loading: true }).then(function (res) {
            result.textContent = res.message || '';
        });
    });

    document.getElementById('btn-issue').addEventListener('click', function () {
        var btn = this;
        var selected = criteria();
        btn.disabled = true;
        MubloRequest.requestJson(base + '/preview', selected, { loading: true }).then(function (res) {
            var count = (res.data && res.data.count) || 0;
            if (count === 0) {
                result.textContent = '조건에 맞는 회원이 없습니다.';
                btn.disabled = false;
                return;
            }
            if (!confirm('남은 대상 ' + count.toLocaleString() + '명 중 이번에 최대 <?= (int) $maxTargets ?>명에게 지급할까요?\n지급한 쿠폰은 일괄 회수할 수 없습니다.')) {
                btn.disabled = false;
                return;
            }
            btn.disabled = true;
            return MubloRequest.requestJson(base, selected, { loading: true })
                .then(function (r) {
                    result.textContent = r.message || '지급했습니다.';
                    if (r.data && r.data.truncated && r.data.next_after_member_id) {
                        pendingCriteria = Object.assign({}, selected, { after_member_id: r.data.next_after_member_id });
                        box.querySelectorAll('input, select').forEach(function (el) { el.disabled = true; });
                        btn.textContent = '계속 지급';
                        btn.disabled = false;
                        return;
                    }
                    alert(r.message || '지급했습니다.');
                    location.href = '/admin/shop/coupons/<?= $couponId ?>/issued';
                })
                .catch(function () { btn.disabled = false; });
        }).catch(function () { btn.disabled = false; });
    });
});
</script>
