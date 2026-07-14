<?php
/**
 * 등급별 가격 정책 관리
 *
 * @var string $pageTitle
 * @var array $levels 회원 등급 목록
 * @var array $policyMap [level_value => policy]
 */
$levels = $levels ?? [];
$policyMap = $policyMap ?? [];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '등급별 가격 정책') ?></h3>
            <p>회원 등급별 할인율, 적립률, 무료배송 정책을 설정합니다.</p>
        </div>
    </div>

    <?php if (empty($levels)): ?>
    <div class="alert alert-info mt-4">등록된 회원 등급이 없습니다. 먼저 회원 등급을 설정해 주세요.</div>
    <?php else: ?>
    <div class="page-block">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>등급명</th>
                        <th style="width:100px" class="text-center">등급값</th>
                        <th style="width:120px" class="text-center">할인율(%)</th>
                        <th style="width:120px" class="text-center">적립률(%)</th>
                        <th style="width:120px" class="text-center">무료배송</th>
                        <th style="width:150px" class="text-center">무료배송 기준액</th>
                        <th style="width:100px" class="text-center">관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($levels as $level):
                        $lv = (int) $level['level_value'];
                        $policy = $policyMap[$lv] ?? null;
                        $discountRate = $policy ? (float) $policy['discount_rate'] : 0;
                        $rewardRate = $policy ? (float) $policy['reward_rate'] : 0;
                        $freeShipping = $policy ? (bool) $policy['free_shipping'] : false;
                        $freeThreshold = $policy ? (int) $policy['free_shipping_threshold'] : 0;
                    ?>
                    <tr data-level="<?= $lv ?>">
                        <td>
                            <strong><?= htmlspecialchars($level['level_name'] ?? '등급 ' . $lv) ?></strong>
                            <?php if (!empty($level['is_super'])): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle ms-1">최고관리자</span>
                            <?php elseif (!empty($level['is_admin'])): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1">관리자</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= $lv ?></td>
                        <td class="text-center">
                            <input type="number" class="form-control form-control-sm text-center policy-discount"
                                   step="0.01" min="0" max="100" value="<?= $discountRate ?>" style="max-width:90px;margin:auto">
                        </td>
                        <td class="text-center">
                            <input type="number" class="form-control form-control-sm text-center policy-reward"
                                   step="0.01" min="0" max="100" value="<?= $rewardRate ?>" style="max-width:90px;margin:auto">
                        </td>
                        <td class="text-center">
                            <div class="form-check form-switch d-flex justify-content-center">
                                <input type="checkbox" class="form-check-input policy-free-shipping" <?= $freeShipping ? 'checked' : '' ?>>
                            </div>
                        </td>
                        <td class="text-center">
                            <input type="number" class="form-control form-control-sm text-center policy-threshold"
                                   min="0" value="<?= $freeThreshold ?>" placeholder="0=무조건" style="max-width:120px;margin:auto">
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary" onclick="LevelPricing.save(<?= $lv ?>)">저장</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="text-muted mt-2" style="font-size:0.85rem">
            * 할인율 0% = 정책 미적용 / 무료배송 기준액 0 = 금액 무관 무료배송
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const LevelPricing = {
    save(levelValue) {
        const row = document.querySelector(`tr[data-level="${levelValue}"]`);
        const data = {
            level_value: levelValue,
            discount_rate: parseFloat(row.querySelector('.policy-discount').value) || 0,
            reward_rate: parseFloat(row.querySelector('.policy-reward').value) || 0,
            free_shipping: row.querySelector('.policy-free-shipping').checked ? 1 : 0,
            free_shipping_threshold: parseInt(row.querySelector('.policy-threshold').value) || 0,
        };

        MubloRequest.requestJson('/admin/shop/level-pricing/store', data)
            .then(res => {
                MubloRequest.showToast('저장되었습니다.', 'success');
            });
    }
};
</script>
