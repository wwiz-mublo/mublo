<?php
/**
 * Front Footer (basic frame skin)
 *
 * 섹션 순서:
 * 1. footer-nav   — 푸터 메뉴 (이용약관, 개인정보처리방침 등)
 * 2. footer-head  — 왼쪽: 로고 + SNS + 고객센터 / 오른쪽: 회사 정보
 * 3. footer-bar   — 카피라이트
 *
 * 푸터 메뉴·SNS·사업자 정보·고객센터·테마 스위치는 공용 컴포넌트
 * (views/Components/frame/*)로 렌더한다 — 프레임 템플릿 슬롯과 공유.
 *
 * @var array $mublo Front View 데이터 계약
 */

$siteConfig = $mublo['site']['config'];
$companyConfig = $mublo['site']['company'];
$seoConfig = $mublo['site']['seo'];
$siteImages = $mublo['site']['images'];
$csInfo = $mublo['site']['customerService'];
$navigation = $mublo['navigation'];
$viewer = $mublo['viewer'];
$year     = date('Y');
$siteName = htmlspecialchars($siteConfig['site_title'] ?? '');
$logoUrl  = $siteImages['logo_pc'] ?? '';

$hasBrand = $logoUrl || $siteName;

$snsHtml = $this->component('frame/sns', ['seoConfig' => $seoConfig ?? []]);
$hasSns  = trim($snsHtml) !== '';
?>
<footer class="mublo-footer">
    <div class="mublo-container">

<?= $this->component('frame/menu_footer', ['footerMenus' => $navigation['footerMenus'] ?? [], 'viewer' => $viewer]) ?>

        <div class="footer-body">
            <div class="footer-company">
                <?php if ($hasBrand || $hasSns): ?>
                <div class="footer-brand-row">
                    <?php if ($hasBrand): ?>
                    <div class="footer-brand">
                        <?php if ($logoUrl): ?>
                        <a href="/" class="footer-logo">
                            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= $siteName ?>">
                        </a>
                        <?php elseif ($siteName): ?>
                        <a href="/" class="footer-logo-text"><?= $siteName ?></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

<?= $snsHtml ?>
                </div>
                <?php endif; ?>

<?= $this->component('frame/business_info', ['companyConfig' => $companyConfig ?? [], 'csInfo' => $csInfo ?? []]) ?>

                <div class="footer-copy">&copy; <?= $year ?><?= $siteName ? ' ' . $siteName . '.' : '' ?> All Rights Reserved.</div>
            </div>

<?= $this->component('frame/cs_info', ['csInfo' => $csInfo ?? [], 'companyConfig' => $companyConfig ?? []]) ?>
        </div>

        <div class="footer-bar">
<?= $this->component('frame/theme_switch') ?>
        </div>

    </div>
</footer>
