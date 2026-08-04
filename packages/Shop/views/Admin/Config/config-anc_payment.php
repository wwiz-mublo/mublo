<?php
/**
 * 쇼핑몰 설정 - 결제 설정
 *
 * @var array $config 쇼핑몰 설정
 * @var array $paymentGateways ContractRegistry에서 조회한 PG사 메타 [key => [label, ...]]
 * @var \Mublo\Contract\Member\MemberLevelDescriptor[] $memberLevels 회원 레벨 목록
 */

$paymentGateways = $paymentGateways ?? [];
$paymentPgKeys = $paymentPgKeys ?? [];
$memberLevels = $memberLevels ?? [];

// 레벨별 포인트 설정 파싱
$pointLevelSettings = [];
if (!empty($config['point_level_settings'])) {
    $decoded = is_string($config['point_level_settings']) ? json_decode($config['point_level_settings'], true) : $config['point_level_settings'];
    $pointLevelSettings = is_array($decoded) ? $decoded : [];
}

// 무통장 입금 파싱: payment_bank_info(JSON) → 사용여부 + 계좌목록
// 저장 포맷: {"enabled":0|1,"accounts":[{"bank":"","account":"","holder":""}]}
$bankInfoRaw = is_string($config['payment_bank_info'] ?? '') ? ($config['payment_bank_info'] ?? '') : '';
$bankEnabled = 0;
$bankAccounts = [];
if ($bankInfoRaw !== '') {
    $decoded = json_decode($bankInfoRaw, true);
    if (is_array($decoded)) {
        if (isset($decoded['accounts']) && is_array($decoded['accounts'])) {
            $bankEnabled = !empty($decoded['enabled']) ? 1 : 0;
            $bankAccounts = $decoded['accounts'];
        } elseif (isset($decoded[0]) && is_array($decoded[0])) {
            $bankAccounts = $decoded;
        }
    }
}
?>
<!-- PG 연동 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-credit-card text-pastel-blue"></i>
        <span>PG 연동</span>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-12">
                <?php
                // 신규(PG 저장 이력 없음)이고 테스트 결제가 등록돼 있으면 TestPay 기본 체크
                $checkedPgKeys = $paymentPgKeys;
                if (($config['payment_pg_keys'] ?? '') === '' && isset($paymentGateways['testpay'])) {
                    $checkedPgKeys = ['testpay'];
                }
                ?>
                <?php if (!empty($paymentGateways)): ?>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($paymentGateways as $key => $meta): ?>
                    <div class="form-check">
                        <input type="checkbox" name="formData[payment_pg_keys_arr][]" class="form-check-input"
                               id="pgk_<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($key) ?>"
                               <?= in_array((string) $key, $checkedPgKeys, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pgk_<?= htmlspecialchars($key) ?>">
                            <?= htmlspecialchars($meta['label'] ?? $key) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="form-text">결제 플러그인을 설치하면 PG사를 선택할 수 있습니다.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 무통장 입금 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-bank text-pastel-orange"></i>
        <span>무통장 입금</span>
    </div>
    <div class="card-body">
        <div class="form-check form-switch mb-3">
            <input type="checkbox" id="use_bank_transfer" class="form-check-input" value="1" <?= $bankEnabled ? 'checked' : '' ?>>
            <label class="form-check-label" for="use_bank_transfer">무통장 입금 사용</label>
        </div>

        <label class="form-label">입금 계좌</label>
        <div id="bankAccountList">
            <?php
            $rows = !empty($bankAccounts) ? $bankAccounts : [['bank' => '', 'account' => '', 'holder' => '']];
            foreach ($rows as $acc):
            ?>
            <div class="bank-account-row d-flex gap-2 align-items-center mb-2" data-bank-row>
                <input type="text" class="form-control flex-fill" data-bank-field="bank"
                       placeholder="은행명" value="<?= htmlspecialchars($acc['bank'] ?? '') ?>">
                <input type="text" class="form-control flex-fill" data-bank-field="account"
                       placeholder="계좌번호" value="<?= htmlspecialchars($acc['account'] ?? '') ?>">
                <input type="text" class="form-control flex-fill" data-bank-field="holder"
                       placeholder="예금주" value="<?= htmlspecialchars($acc['holder'] ?? '') ?>">
                <button type="button" class="btn btn-outline-danger bank-account-remove" data-bank-remove aria-label="계좌 삭제">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="btn btn-outline-secondary" id="bankAccountAdd">
            <i class="bi bi-plus-lg"></i> 계좌 추가
        </button>

        <div class="form-text">체크아웃 시 고객에게 입금 계좌로 안내됩니다. 계좌가 여러 개면 고객이 선택합니다.</div>

        <!-- 행 복제 템플릿 (JS 연동 시 사용) -->
        <template id="bankAccountTemplate">
            <div class="bank-account-row d-flex gap-2 align-items-center mb-2" data-bank-row>
                <input type="text" class="form-control flex-fill" data-bank-field="bank" placeholder="은행명">
                <input type="text" class="form-control flex-fill" data-bank-field="account" placeholder="계좌번호">
                <input type="text" class="form-control flex-fill" data-bank-field="holder" placeholder="예금주">
                <button type="button" class="btn btn-outline-danger bank-account-remove" data-bank-remove aria-label="계좌 삭제">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </template>

        <!-- JS 연동 예정: 토글+행 → JSON 직렬화하여 기록. 현재는 기존 값 라운드트립 -->
        <input type="hidden" name="formData[payment_bank_info]" id="bankInfoHidden" value="<?= htmlspecialchars($bankInfoRaw) ?>">
    </div>
</div>

<!-- 포인트 결제 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-coin text-pastel-green"></i>
        <span>포인트 결제</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input type="hidden" name="formData[use_point_payment]" value="0">
                    <input type="checkbox" name="formData[use_point_payment]" id="use_point_payment" class="form-check-input"
                           value="1" <?= !empty($config['use_point_payment']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="use_point_payment">포인트 결제 허용</label>
                </div>
            </div>
        </div>
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12 col-sm-4">
                <label for="point_unit" class="form-label">사용 단위</label>
                <div class="input-group">
                    <input type="number" name="formData[point_unit]" id="point_unit" class="form-control"
                           value="<?= (int)($config['point_unit'] ?? 100) ?>" min="1">
                    <span class="input-group-text">P</span>
                </div>
                <div class="form-text">포인트 사용 최소 단위</div>
            </div>
        </div>

        <?php if (!empty($memberLevels)): ?>
        <label class="form-label fw-semibold">레벨별 최소/최대 사용</label>
        <div class="d-flex flex-wrap gap-2 mt-1">
            <?php foreach ($memberLevels as $level):
                $lid = $level->levelId;
                $ls = $pointLevelSettings[$lid] ?? [];
            ?>
            <div class="level-card level-card--wide">
                <div class="level-card__name"><?= htmlspecialchars($level->name) ?></div>
                <div class="level-card__row">
                    <div class="level-card__label">최소</div>
                    <input type="number" name="formData[point_level_settings][<?= $lid ?>][min]"
                           class="form-control form-control-sm"
                           value="<?= (int)($ls['min'] ?? ($config['point_min'] ?? 100)) ?>" min="0">
                </div>
                <div class="level-card__row">
                    <div class="level-card__label">최대</div>
                    <input type="number" name="formData[point_level_settings][<?= $lid ?>][max]"
                           class="form-control form-control-sm"
                           value="<?= (int)($ls['max'] ?? ($config['point_max'] ?? 30000)) ?>" min="0">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="row gy-3 gy-md-0">
            <div class="col-12 col-sm-4">
                <label for="point_min" class="form-label">최소 사용</label>
                <div class="input-group">
                    <input type="number" name="formData[point_min]" id="point_min" class="form-control"
                           value="<?= (int)($config['point_min'] ?? 100) ?>" min="0">
                    <span class="input-group-text">P</span>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <label for="point_max" class="form-label">최대 사용</label>
                <div class="input-group">
                    <input type="number" name="formData[point_max]" id="point_max" class="form-control"
                           value="<?= (int)($config['point_max'] ?? 30000) ?>" min="0">
                    <span class="input-group-text">P</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
