<?php
/**
 * 쇼핑몰 설정 - 할인 / 적립
 *
 * @var array $config 쇼핑몰 설정
 * @var \Mublo\Entity\Member\MemberLevel[] $memberLevels 회원 레벨 목록
 */

use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;

$memberLevels = $memberLevels ?? [];

// 레벨별 할인 설정 파싱
$discountLevelSettings = [];
if (!empty($config['discount_level_settings'])) {
    $decoded = is_string($config['discount_level_settings']) ? json_decode($config['discount_level_settings'], true) : $config['discount_level_settings'];
    $discountLevelSettings = is_array($decoded) ? $decoded : [];
}

// 레벨별 적립 설정 파싱
$rewardLevelSettings = [];
if (!empty($config['reward_level_settings'])) {
    $decoded = is_string($config['reward_level_settings']) ? json_decode($config['reward_level_settings'], true) : $config['reward_level_settings'];
    $rewardLevelSettings = is_array($decoded) ? $decoded : [];
}

$currentDiscountType = $config['discount_type'] ?? 'NONE';
$currentRewardType = $config['reward_type'] ?? 'NONE';
?>

<!-- 기본 할인 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-percent text-pastel-blue"></i>
        <span>기본 할인</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="discount_type" class="form-label">할인 유형</label>
                <select name="formData[discount_type]" id="discount_type" class="form-select">
                    <?php foreach (DiscountType::options() as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $currentDiscountType === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">전체 상품에 적용되는 기본 할인</div>
            </div>
            <div class="col-12 col-sm-6 col-md-4" id="discountValueWrap">
                <label for="discount_value" class="form-label">할인 값</label>
                <input type="number" name="formData[discount_value]" id="discount_value" class="form-control"
                       value="<?= $config['discount_value'] ?? 0 ?>" step="0.01" min="0">
                <div class="form-text">정률: %(백분율) / 정액: 원(고정금액)</div>
            </div>
        </div>

        <!-- 등급별 할인 카드 -->
        <div id="discountLevelTable" style="display:<?= $currentDiscountType === 'LEVEL' ? 'block' : 'none' ?>;">
            <?php if (!empty($memberLevels)): ?>
            <label class="form-label fw-semibold">레벨별 할인 값</label>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($memberLevels as $level):
                    $lid = $level->getLevelId();
                    $ls = $discountLevelSettings[$lid] ?? [];
                ?>
                <div class="level-card">
                    <div class="level-card__name"><?= htmlspecialchars($level->getLevelName()) ?></div>
                    <select name="formData[discount_level_settings][<?= $lid ?>][type]" class="form-select form-select-sm level-card__type">
                        <option value="PERCENTAGE" <?= ($ls['type'] ?? 'PERCENTAGE') === 'PERCENTAGE' ? 'selected' : '' ?>>정률(%)</option>
                        <option value="FIXED" <?= ($ls['type'] ?? '') === 'FIXED' ? 'selected' : '' ?>>정액(원)</option>
                    </select>
                    <input type="number" name="formData[discount_level_settings][<?= $lid ?>][value]"
                           class="form-control form-control-sm level-card__value"
                           value="<?= $ls['value'] ?? 0 ?>" step="0.01" min="0">
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-muted small">등록된 회원 레벨이 없습니다.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 기본 적립 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-piggy-bank text-pastel-green"></i>
        <span>기본 적립</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="reward_type" class="form-label">적립 유형</label>
                <select name="formData[reward_type]" id="reward_type" class="form-select">
                    <?php foreach (RewardType::options() as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $currentRewardType === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">구매 시 자동 적립되는 포인트</div>
            </div>
            <div class="col-12 col-sm-6 col-md-4" id="rewardValueWrap">
                <label for="reward_value" class="form-label">적립 값</label>
                <input type="number" name="formData[reward_value]" id="reward_value" class="form-control"
                       value="<?= $config['reward_value'] ?? 0 ?>" step="0.01" min="0">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="reward_review" class="form-label">구매후기 적립금</label>
                <div class="input-group">
                    <input type="number" name="formData[reward_review]" id="reward_review" class="form-control"
                           value="<?= (int)($config['reward_review'] ?? 0) ?>" min="0">
                    <span class="input-group-text">원</span>
                </div>
                <div class="form-text">구매후기 작성 시 지급 (전 등급 동일)</div>
            </div>
        </div>

        <!-- 등급별 적립 카드 -->
        <div id="rewardLevelTable" style="display:<?= $currentRewardType === 'LEVEL' ? 'block' : 'none' ?>;">
            <?php if (!empty($memberLevels)): ?>
            <label class="form-label fw-semibold">레벨별 적립 값</label>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($memberLevels as $level):
                    $lid = $level->getLevelId();
                    $ls = $rewardLevelSettings[$lid] ?? [];
                ?>
                <div class="level-card">
                    <div class="level-card__name"><?= htmlspecialchars($level->getLevelName()) ?></div>
                    <select name="formData[reward_level_settings][<?= $lid ?>][type]" class="form-select form-select-sm level-card__type">
                        <option value="PERCENTAGE" <?= ($ls['type'] ?? 'PERCENTAGE') === 'PERCENTAGE' ? 'selected' : '' ?>>정률(%)</option>
                        <option value="FIXED" <?= ($ls['type'] ?? '') === 'FIXED' ? 'selected' : '' ?>>정액(원)</option>
                    </select>
                    <input type="number" name="formData[reward_level_settings][<?= $lid ?>][value]"
                           class="form-control form-control-sm level-card__value"
                           value="<?= $ls['value'] ?? 0 ?>" step="0.01" min="0">
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-muted small">등록된 회원 레벨이 없습니다.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
