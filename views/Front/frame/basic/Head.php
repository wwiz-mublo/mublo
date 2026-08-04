<?php
/**
 * Front Head (basic frame skin)
 *
 * HTML 시작, <head>, meta 태그, CSS 로드, <body> 시작
 *
 * @var string|null $pageTitle 페이지 제목 (Controller에서 전달)
 * @var string|null $seoTitle SEO 타이틀 (Controller에서 전달, 페이지별 오버라이드)
 * @var string|null $seoDescription SEO 설명 (Controller에서 전달, 페이지별 오버라이드)
 * @var string|null $seoKeywords SEO 키워드 (Controller에서 전달, 페이지별 오버라이드)
 * @var array $mublo Front View 데이터 계약
 */
$siteConfig = $mublo['site']['config'];
$seoConfig = $mublo['site']['seo'];
$siteImages = $mublo['site']['images'];
$companyConfig = $mublo['site']['company'];
$csrfToken = $mublo['security']['csrfToken'];
$currentUrl = $mublo['request']['url'];
$frameSkin = $mublo['theme']['frameSkin'];

/**
 * 스킨 에셋 버전 (캐시 버스팅)
 */
function frontAssetVersion(string $path): string
{
    $fullPath = __DIR__ . '/_assets' . $path;
    return file_exists($fullPath) ? (string) filemtime($fullPath) : (string) time();
}

// 빈 문자열도 fallback 되도록 처리
$title = (!empty($seoTitle) ? $seoTitle : null)
    ?? (!empty($pageTitle) ? $pageTitle : null)
    ?? (!empty($seoConfig['meta_title']) ? $seoConfig['meta_title'] : null)
    ?? ($siteConfig['site_title'] ?? '');
$description = (!empty($seoDescription) ? $seoDescription : null)
    ?? ($seoConfig['meta_description'] ?? '');
$keywords = (!empty($seoKeywords) ? $seoKeywords : null)
    ?? ($seoConfig['meta_keywords'] ?? '');
$siteName = $siteConfig['site_title'] ?? '';

$gaId = trim($seoConfig['google_analytics'] ?? '');
$googleVerify = trim($seoConfig['google_site_verification'] ?? '');
$naverVerify = trim($seoConfig['naver_site_verification'] ?? '');
$favicon = trim($siteImages['favicon'] ?? $seoConfig['favicon'] ?? '');
$appIcon = trim($siteImages['app_icon'] ?? $seoConfig['app_icon'] ?? '');
// 사이트 기본 og:image (업로드 없으면 코어 기본 에셋으로 폴백)
$defaultOgImage = trim($siteImages['og_image'] ?? $seoConfig['og_image'] ?? '') ?: asset('/assets/images/ogimg.png');
// 페이지별 og:image: Controller 에서 $pageOgImage 전달 (게시글 영상 썸네일 등)
// 기존 $seoImage 변수도 하위 호환으로 인정
$pageOgImageVar = !empty($pageOgImage) ? trim((string) $pageOgImage) : (!empty($seoImage) ? trim((string) $seoImage) : '');
$ogImage = $pageOgImageVar !== '' ? $pageOgImageVar : $defaultOgImage;
$ogType = !empty($pageOgType) ? (string) $pageOgType : 'website';
$articleMeta = isset($articleMeta) && is_array($articleMeta) ? $articleMeta : [];
$pageJsonLd = isset($pageJsonLd) && is_array($pageJsonLd) ? $pageJsonLd : [];
$metaPixel = trim($seoConfig['meta_pixel_id'] ?? '');
$kakaoPixel = trim($seoConfig['kakao_pixel_id'] ?? '');
$naverAna = trim($seoConfig['naver_analytics_id'] ?? '');
$customHead = trim($seoConfig['custom_head_script'] ?? '');
$canonicalUrl = $currentUrl ?? '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken ?? '') ?>">
<?php /* 다크모드 무플래시: paint 전에 <html>에 테마 클래스 결정 (localStorage > 시스템설정) */ ?>
<script>
(function(){try{var t=localStorage.getItem('mublo-theme');if(!t||t==='auto'||t==='system'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}if(t==='dark'){var e=document.documentElement;e.classList.add('dark');e.setAttribute('data-theme','dark');}}catch(_){}})();
</script>
<?php if ($googleVerify): ?>
<meta name="google-site-verification" content="<?= htmlspecialchars($googleVerify) ?>">
<?php endif; ?>
<?php if ($naverVerify): ?>
<meta name="naver-site-verification" content="<?= htmlspecialchars($naverVerify) ?>">
<?php endif; ?>
<?php if ($description): ?>
<meta name="description" content="<?= htmlspecialchars($description) ?>">
<?php endif; ?>
<?php if ($keywords): ?>
<meta name="keywords" content="<?= htmlspecialchars($keywords) ?>">
<?php endif; ?>
<title><?= htmlspecialchars($title) ?></title>
<?php if ($canonicalUrl): ?>
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
<?php endif; ?>
<?php if ($favicon): ?>
<link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($favicon) ?>">
<?php else: ?>
<link rel="icon" type="image/x-icon" href="<?= asset('/favicon.ico') ?>">
<?php endif; ?>
<link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($appIcon ?: asset('/assets/images/app-icon.png')) ?>">
<?php if ($title): ?>
<meta property="og:title" content="<?= htmlspecialchars($title) ?>">
<?php endif; ?>
<?php if ($description): ?>
<meta property="og:description" content="<?= htmlspecialchars($description) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= htmlspecialchars($ogType) ?>">
<?php if ($ogType === 'article'):
    $publishedTime = $articleMeta['published_time'] ?? null;
    $modifiedTime = $articleMeta['modified_time'] ?? null;
    $articleAuthor = $articleMeta['author'] ?? null;
    $articleSection = $articleMeta['section'] ?? null;
    if ($publishedTime):
        try { $publishedTime = (new DateTimeImmutable((string) $publishedTime))->format(DateTimeImmutable::ATOM); } catch (Exception) {}
?>
<meta property="article:published_time" content="<?= htmlspecialchars((string) $publishedTime) ?>">
<?php endif; ?>
<?php if ($modifiedTime):
        try { $modifiedTime = (new DateTimeImmutable((string) $modifiedTime))->format(DateTimeImmutable::ATOM); } catch (Exception) {}
?>
<meta property="article:modified_time" content="<?= htmlspecialchars((string) $modifiedTime) ?>">
<?php endif; ?>
<?php if ($articleAuthor): ?>
<meta property="article:author" content="<?= htmlspecialchars((string) $articleAuthor) ?>">
<?php endif; ?>
<?php if ($articleSection): ?>
<meta property="article:section" content="<?= htmlspecialchars((string) $articleSection) ?>">
<?php endif; ?>
<?php endif; ?>
<?php if ($canonicalUrl): ?>
<meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
<?php endif; ?>
<?php if ($siteName): ?>
<meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
<?php endif; ?>
<?php if ($ogImage): ?>
<meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<?php if ($title): ?>
<meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
<?php endif; ?>
<?php if ($description): ?>
<meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
<?php endif; ?>
<?php if ($ogImage): ?>
<meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
<?php endif; ?>
<?php if ($gaId): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($gaId) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= htmlspecialchars($gaId) ?>');</script>
<?php endif; ?>
<?php if ($metaPixel): ?>
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','<?= htmlspecialchars($metaPixel) ?>');fbq('track','PageView');</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?= htmlspecialchars($metaPixel) ?>&ev=PageView&noscript=1"/></noscript>
<?php endif; ?>
<?php if ($kakaoPixel): ?>
<script type="text/javascript" charset="UTF-8" src="//t1.daumcdn.net/kas/static/kp.js"></script>
<script type="text/javascript">kakaoPixel('<?= htmlspecialchars($kakaoPixel) ?>').pageView();</script>
<meta name="kakao-pixel" data-kakao-pixel="<?= htmlspecialchars($kakaoPixel) ?>">
<?php endif; ?>
<?php if ($naverAna): ?>
<script type="text/javascript" src="//wcs.naver.net/wcslog.js"></script>
<script type="text/javascript">if(!wcs_add)var wcs_add={};wcs_add["wa"]=<?= json_encode((string) $naverAna, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;if(window.wcs){wcs_do();}</script>
<?php endif; ?>
<?php if ($customHead): ?>
<?= $customHead ?>
<?php endif; ?>
<?php
// JSON-LD Organization 스키마
$companyConfig = isset($companyConfig) && is_array($companyConfig) ? $companyConfig : [];
$companyName = $companyConfig['company_name'] ?? $siteName;
if ($companyName):
    $orgJsonLd = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $companyName];
    if ($canonicalUrl) { $orgJsonLd['url'] = explode('/', $canonicalUrl, 4)[0] . '//' . parse_url($canonicalUrl, PHP_URL_HOST); }
    // 사이트 로고 자산을 우선 — 페이지 og:image 는 글마다 달라져 Organization.logo 에 부적합
    $orgLogo = trim($siteImages['logo_pc'] ?? $siteImages['og_image'] ?? $seoConfig['og_image'] ?? $favicon ?? '');
    if ($orgLogo) { $orgJsonLd['logo'] = $orgLogo; }
    if (!empty($companyConfig['tel'])) { $orgJsonLd['telephone'] = $companyConfig['tel']; }
    if (!empty($companyConfig['email'])) { $orgJsonLd['email'] = $companyConfig['email']; }
    if (!empty($companyConfig['company_address'])) { $orgJsonLd['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $companyConfig['company_address']]; }
?>
<script type="application/ld+json"><?= json_encode($orgJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endif; ?>
<?php foreach ($pageJsonLd as $extraJsonLd): if (is_array($extraJsonLd)): ?>
<script type="application/ld+json"><?= json_encode($extraJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endif; endforeach; ?>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@100..900&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Serif+KR:wght@200..900&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,100..800;1,100..800&display=swap">

<!-- Icon Fonts -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

<!-- Swiper -->
<link rel="stylesheet" href="/assets/lib/swiper/12/swiper-bundle.min.css">
<script src="/assets/lib/swiper/12/swiper-bundle.min.js"></script>

<!-- AOS (Animate On Scroll) -->
<link rel="stylesheet" href="/assets/lib/aos/2/dist/aos.css">

<!-- Design Tokens (Tier1 primitive + Tier2 semantic + dark) -->
<link rel="stylesheet" href="<?= asset('/assets/css/tokens.css') ?>">

<!-- Block System CSS -->
<link rel="stylesheet" href="<?= asset('/assets/css/block.css') ?>">

<!-- Slot CSS: component (스킨 앞 — 스킨이 덮을 수 있게). addCss(path, 'component') -->
<!-- MUBLO_CSS_component -->

<!-- Front Skin CSS -->
<link rel="stylesheet" href="/serve/front/<?= htmlspecialchars($frameSkin ?? 'basic') ?>/css/front.css?<?= frontAssetVersion('/css/front.css') ?>">

<!-- Front Skin JS (스킨 동작 — 모바일 패널 등) -->
<script defer src="/serve/front/<?= htmlspecialchars($frameSkin ?? 'basic') ?>/js/front.js?<?= frontAssetVersion('/js/front.js') ?>"></script>

<?php if (!empty($siteConfig)): ?>
<!-- 도메인별 CSS 변수 -->
<style>
:root {
    <?php if (!empty($siteConfig['primary_color'])): ?>
    <?php /* 도메인 브랜드색이 semantic --primary 를 이긴다 (tokens.css 기본값 override). --color-primary 는 레거시 호환.
           --brand-primary 는 도메인 색이 '명시 설정됐을 때만' 존재 — 토큰 기본값과 구분되므로
           패키지 뷰가 콘텐츠 테마 스킨 팔레트보다 브랜드색을 우선시키는 용도로 쓴다. */ ?>
    --primary: <?= htmlspecialchars($siteConfig['primary_color']) ?>;
    --color-primary: <?= htmlspecialchars($siteConfig['primary_color']) ?>;
    --brand-primary: <?= htmlspecialchars($siteConfig['primary_color']) ?>;
    --brand-primary-hover: <?= htmlspecialchars($siteConfig['primary_color']) ?>;
    <?php endif; ?>
    <?php
    $layoutMaxWidth = (int) ($siteConfig['layout_max_width'] ?? 0);
    $contentMaxWidth = (int) ($siteConfig['content_max_width'] ?? 0);
    $sidebarLeftWidth = (int) ($siteConfig['layout_left_width'] ?? 0);
    $sidebarRightWidth = (int) ($siteConfig['layout_right_width'] ?? 0);
    ?>
    <?php if ($layoutMaxWidth > 0): ?>
    --site-max-width: <?= $layoutMaxWidth ?>px;
    <?php endif; ?>
    <?php if ($contentMaxWidth > 0): ?>
    --content-max-width: <?= $contentMaxWidth ?>px;
    <?php endif; ?>
    <?php if ($sidebarLeftWidth > 0): ?>
    --sidebar-left-width: <?= $sidebarLeftWidth ?>px;
    <?php endif; ?>
    <?php if ($sidebarRightWidth > 0): ?>
    --sidebar-right-width: <?= $sidebarRightWidth ?>px;
    <?php endif; ?>
}
<?php if (!empty($siteConfig['primary_color'])): ?>
/* 브랜드색 hover 파생 (color-mix 지원 브라우저) */
@supports (color: color-mix(in srgb, red, #fff)) {
    :root { --brand-primary-hover: color-mix(in srgb, <?= htmlspecialchars($siteConfig['primary_color']) ?> 85%, #000); }
}
<?php endif; ?>
</style>
<?php endif; ?>

<!-- Slot CSS: default (스킨 뒤 — 슬롯 없는 addCss + 마커 없는 슬롯 폴백) -->
<!-- MUBLO_CSS -->

<!-- Core 컴포넌트 CSS (JS 모듈 동반) -->
<link rel="stylesheet" href="<?= asset('/assets/css/components/mublo-request.css') ?>">

<!-- Core JS (defer) -->
<script defer src="<?= asset('/assets/js/MubloCore.js') ?>"></script>
<script defer src="<?= asset('/assets/js/MubloRequest.js') ?>"></script>
<script defer src="<?= asset('/assets/js/MubloForm.js') ?>"></script>
<script defer src="<?= asset('/assets/js/MubloModal.js') ?>" data-css="<?= asset('/assets/css/components/mublo-modal.css') ?>"></script>
<script defer src="<?= asset('/assets/js/MubloAddress.js') ?>"></script>
<script defer src="<?= asset('/assets/js/MubloPasswordPolicy.js') ?>"></script>
<script defer src="<?= asset('/assets/js/MubloTracking.js') ?>"></script>
<script defer src="<?= asset('/assets/js/MubloItemLayout.js') ?>"></script>
<script defer src="<?= asset('/assets/js/MubloSlider.js') ?>"></script>
</head>
<body>
