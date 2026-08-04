<?php
/**
 * Front Block Page View (basic skin)
 *
 * 블록으로 구성된 페이지 표시
 *
 * @var string $blockHtml   블록 렌더링 HTML
 * @var string $appendHtml  이벤트로 주입된 추가 HTML (사업자 정보 등)
 * @var string $pageTitle   페이지 제목
 * @var array  $_pageConfig 페이지 설정 (CTA 등)
 */

$blockHtml = $blockHtml ?? '';
$appendHtml = $appendHtml ?? '';
$cta = $_pageConfig['cta'] ?? [];
?>
<div>
    <?php if (!empty($blockHtml)): ?>
        <?= $blockHtml ?>
    <?php else: ?>
        <div class="page-view__empty">
            <p>페이지 콘텐츠가 없습니다.</p>
        </div>
    <?php endif; ?>

    <?php // 이벤트로 주입된 추가 HTML (사업자 정보 등) ?>
    <?php if (!empty($appendHtml)): ?>
        <?= $appendHtml ?>
    <?php endif; ?>
</div>

<?php // CTA 고정 바 ?>
<?php if (!empty($cta['enabled']) && !empty($cta['text'])): ?>
<div class="page-cta-bar" id="pageCtaBar">
    <a href="<?= htmlspecialchars($cta['link'] ?? '#') ?>"
       class="page-cta-bar__btn"
       style="background-color:<?= htmlspecialchars($cta['bg_color'] ?? '#0d6efd') ?>;color:<?= htmlspecialchars($cta['text_color'] ?? '#ffffff') ?>">
        <?= htmlspecialchars($cta['text']) ?>
    </a>
</div>
<style>
.page-cta-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    padding: 12px 16px;
    text-align: center;
    background: rgba(0,0,0,0.05);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}
.page-cta-bar__btn {
    display: inline-block;
    width: 100%;
    max-width: 480px;
    padding: 14px 32px;
    border-radius: 8px;
    font-size: 17px;
    font-weight: 700;
    text-decoration: none;
    text-align: center;
    transition: opacity 0.2s;
}
.page-cta-bar__btn:hover {
    opacity: 0.9;
}
</style>
<?php endif; ?>
