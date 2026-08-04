<?php
/**
 * 쇼핑몰 설정 - 약관 설정
 *
 * @var array $config 쇼핑몰 설정
 * @var array $activePolicies 활성 약관 목록 (PolicyDocument DTO 배열)
 */
$activePolicies = $activePolicies ?? [];
?>
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-file-earmark-text text-pastel-blue"></i>
        <span>약관 설정</span>
    </div>
    <div class="card-body">
        <?php if (empty($activePolicies)): ?>
        <div class="text-muted">
            등록된 활성 약관이 없습니다.
            <a href="/admin/policies" class="ms-1">약관 관리로 이동</a>
        </div>
        <?php else: ?>
        <?php
        $checkoutPolicyIds = json_decode($config['checkout_policies'] ?? '', true) ?: [];
        ?>
        <div class="row gy-4">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">주문서 약관</label>
                <div class="form-text mb-2">주문서(Checkout)에 표시할 약관을 선택합니다.</div>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($activePolicies as $policy): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input checkout-policy-check"
                               id="cp_<?= $policy->policyId ?>"
                               value="<?= $policy->policyId ?>"
                               <?= in_array($policy->policyId, $checkoutPolicyIds) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cp_<?= $policy->policyId ?>">
                            <?= htmlspecialchars($policy->title) ?>
                            <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars($policy->typeLabel) ?></span>
                            <?php if ($policy->required): ?>
                            <span class="badge text-bg-danger ms-1">필수</span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="formData[checkout_policies]" id="checkoutPoliciesHidden"
                       value="<?= htmlspecialchars($config['checkout_policies'] ?? '') ?>">
            </div>
        </div>
        <div class="form-text mt-3">
            <a href="/admin/policies" class="text-decoration-none"><i class="bi bi-gear"></i> 약관 관리</a>에서 약관을 등록/수정할 수 있습니다.
        </div>
        <?php endif; ?>
    </div>
</div>
