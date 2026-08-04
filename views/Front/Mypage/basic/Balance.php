<?php
/**
 * Mypage - 포인트 내역
 *
 * @var \Mublo\Entity\Balance\BalanceLog[] $logs       포인트 내역 목록
 * @var array                              $pagination  페이지네이션 (totalItems, perPage, currentPage, totalPages)
 * @var int                                $balance     현재 포인트 잔액
 * @var array[]                            $mypageMenus 사이드바 메뉴 목록
 * @var string                             $currentSection
 */
?>

<?php ob_start(); ?>
<div class="mypage-header">
    <h1 class="mypage-header__title">포인트 내역</h1>
    <p class="mypage-header__desc">적립·사용 내역과 현재 잔액을 확인합니다.</p>
</div>

<!-- 잔액 카드 -->
<div class="balance-card">
    <div>
        <div class="label">현재 포인트 잔액</div>
        <div class="amount"><?= number_format($balance) ?><small>P</small></div>
    </div>
</div>

<!-- 내역 (카드 리스트) -->
<?php if (empty($logs)): ?>
    <div class="balance-empty">포인트 내역이 없습니다.</div>
<?php else: ?>
    <div class="balance-list">
        <?php foreach ($logs as $log): ?>
            <?php $src = $log->getSourceName(); ?>
            <div class="balance-item">
                <div class="balance-item__main">
                    <span class="balance-item__title"><?= htmlspecialchars($log->getMessage() ?: $log->getAction()) ?></span>
                    <span class="balance-item__amount <?= $log->isAddition() ? 'amount-plus' : 'amount-minus' ?>">
                        <?= $log->isAddition() ? '+' : '' ?><?= number_format($log->getAmount()) ?>P
                    </span>
                </div>
                <div class="balance-item__meta">
                    <span class="balance-item__sub"><?= htmlspecialchars(substr($log->getCreatedAt()->format('Y-m-d H:i'), 0, 16)) ?><?= $src !== '' ? ' · ' . htmlspecialchars($src) : '' ?></span>
                    <span class="balance-item__balance">잔액 <?= number_format($log->getBalanceAfter()) ?>P</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- 페이지네이션 (표준 헬퍼 — 윈도잉·쿼리스트링 유지) -->
<?= $this->pagination($pagination) ?>

<?php $content = ob_get_clean(); ?>

<?php include __DIR__ . '/_layout.php'; ?>
