<?php
/**
 * 기획전 목록 (프론트 기본 스킨)
 *
 * @var string $pageTitle
 * @var array  $exhibitions  진행 중인 기획전 목록
 * @var string $error        (optional) 오류 메시지
 */
$exhibitions = $exhibitions ?? [];
$error       = $error ?? '';

$this->assets->addCss('/serve/package/Shop/views/Front/Exhibition/basic/_assets/css/exhibition-list.css');
?>

<div class="shop-exhibition-list">

    <h2 class="shop-exhibition-list__title"><?= e($pageTitle ?? '기획전') ?></h2>

    <?php if ($error !== ''): ?>
    <div class="shop-exhibition-list__error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($exhibitions)): ?>
    <div class="shop-exhibition-list__empty">
        <i class="bi bi-megaphone"></i>
        <p>현재 진행 중인 기획전이 없습니다.</p>
    </div>
    <?php else: ?>
    <div class="shop-exhibition-grid">
        <?php foreach ($exhibitions as $ex):
            $startDate = !empty($ex['start_date']) ? substr($ex['start_date'], 0, 10) : '';
            $endDate   = !empty($ex['end_date'])   ? substr($ex['end_date'], 0, 10)   : '';
            $period    = trim($startDate . (($startDate && $endDate) ? ' ~ ' : '') . $endDate);
            if ($period === '') {
                $period = '상시';
            }
            $slug = trim((string) ($ex['slug'] ?? ''));
            $url  = '/shop/exhibitions/' . ($slug !== '' ? rawurlencode($slug) : (int) $ex['exhibition_id']);
        ?>
        <a href="<?= $url ?>" class="shop-exhibition-card">
            <div class="shop-exhibition-card__banner">
                <?php if (!empty($ex['banner_image'])): ?>
                <img src="<?= e($ex['banner_image']) ?>" alt="<?= e($ex['title']) ?>">
                <?php else: ?>
                <div class="shop-exhibition-card__banner-placeholder"><i class="bi bi-image"></i></div>
                <?php endif; ?>
            </div>
            <div class="shop-exhibition-card__body">
                <span class="shop-exhibition-card__badge">진행 중</span>
                <span class="shop-exhibition-card__name"><?= e($ex['title']) ?></span>
                <div class="shop-exhibition-card__period">
                    <i class="bi bi-calendar3"></i><span><?= e($period) ?></span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
