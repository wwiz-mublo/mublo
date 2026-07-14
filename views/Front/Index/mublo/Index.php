<?php
$siteImages = $mublo['site']['images'];
$seoConfig = $mublo['site']['seo'];
$currentUrl = $mublo['request']['url'];
/**
 * Front Index Page (mublo skin) — 단일페이지(standalone)
 *
 * $this->layout(['standalone' => true]) 로 프레임 스킨(Head/Header/Layout/Footer/Foot)을
 * 일절 로딩하지 않고, 이 파일이 <html>부터 통째로 문서를 구성한다.
 *
 * Tailwind CSS Play CDN 기반. darkMode: 'selector' (표준 .dark 클래스 방식),
 * 토글 스크립트가 <html>의 .dark 클래스를 켜고 끄며 localStorage에 저장한다.
 *
 */

$this->layout(['standalone' => true]);

// plugins/packages 디렉터리 수 — 컨트롤러 의존 없이 스킨이 직접 카운트 (자기완결)
$pluginCount  = count(glob(MUBLO_PLUGIN_PATH . '/*', GLOB_ONLYDIR) ?: []);
$packageCount = count(glob(MUBLO_PACKAGE_PATH . '/*', GLOB_ONLYDIR) ?: []);

// 사이트 설정 아이콘 (공통데이터로 주입됨) — 프레임 Head.php와 동일 소스
$siteImages = $siteImages ?? [];
$seoConfig  = $seoConfig ?? [];
$favicon = trim($siteImages['favicon'] ?? $seoConfig['favicon'] ?? '');
$appIcon = trim($siteImages['app_icon'] ?? $seoConfig['app_icon'] ?? '');

// SEO / OG / 애널리틱스 / 사이트검증 — 프레임 Head.php와 동일 소스($seoConfig·$siteImages·$currentUrl)
$currentUrl = $currentUrl ?? '';
$metaTitle       = 'MUBLO - PHP 웹 플랫폼, 다시 설계하다';
$metaDescription = 'Core는 가볍게, 확장은 자유롭게. 멀티 도메인, 블록 시스템, Plugin + Package 아키텍처를 갖춘 모던 PHP 웹 플랫폼.';
$ogImage      = trim($siteImages['og_image'] ?? $seoConfig['og_image'] ?? '') ?: asset('/assets/images/ogimg.png');
$canonicalUrl = $currentUrl;
$googleVerify = trim($seoConfig['google_site_verification'] ?? '');
$naverVerify  = trim($seoConfig['naver_site_verification'] ?? '');
$gaId         = trim($seoConfig['google_analytics'] ?? '');
$metaPixel    = trim($seoConfig['meta_pixel_id'] ?? '');
$kakaoPixel   = trim($seoConfig['kakao_pixel_id'] ?? '');
$naverAna     = trim($seoConfig['naver_analytics_id'] ?? '');
$customHead   = trim($seoConfig['custom_head_script'] ?? '');

/*
 * 서브사이트 바로가기 카드 — 히어로 섹션 끝에 출력.
 *
 * 이 스킨(mublo/Index.php)은 git으로 관리되는 "샘플"이다. 기본 상태에선 JSON이
 * 없어 카드가 출력되지 않는다(= 기본). 사이트 운영자는 추적되는 이 파일을 건드리지
 * 않고, storage/custom/landing.json (gitignore)을 두는 것만으로 카드를 띄울 수 있다.
 * (storage/custom 은 유니크 파일명으로 떨구는 범용 커스텀 JSON 버킷)
 * JSON 사용 여부·내용은 전적으로 운영자 자유.
 *
 * 포맷: { "title": "...", "titleLeadingIcon": "bi-...", "titleTrailingIcon": "bi-...",
 *         "links": [ { "label", "desc", "url", "icon", "target", "demoUrl"?, "demoId"?, "demoPw"? } ] }
 *         demoUrl/demoId/demoPw 는 데모 사이트일 때만 넣으면 카드에 접속경로·계정이 표시됨.
 */
$siteLinks = [];
$siteLinksTitle = '';
$siteLinksTitleLeadingIcon = '';
$siteLinksTitleTrailingIcon = '';
$linksJsonPath = MUBLO_STORAGE_PATH . '/custom/landing.json';
if (is_file($linksJsonPath)) {
    $decoded = json_decode((string) file_get_contents($linksJsonPath), true);
    if (is_array($decoded['links'] ?? null)) {
        $siteLinks = $decoded['links'];
        $siteLinksTitle = (string) ($decoded['title'] ?? '');
        $siteLinksTitleLeadingIcon = (string) ($decoded['titleLeadingIcon'] ?? '');
        $siteLinksTitleTrailingIcon = (string) ($decoded['titleTrailingIcon'] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="ko" class="dark scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($metaTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
    <?php if ($canonicalUrl): ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
    <?php endif; ?>

    <!-- 사이트 등록 검증 -->
    <?php if ($googleVerify): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars($googleVerify) ?>">
    <?php endif; ?>
    <?php if ($naverVerify): ?>
    <meta name="naver-site-verification" content="<?= htmlspecialchars($naverVerify) ?>">
    <?php endif; ?>

    <!-- Open Graph / Twitter -->
    <meta property="og:title" content="<?= htmlspecialchars($metaTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDescription) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="MUBLO">
    <?php if ($canonicalUrl): ?>
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <?php endif; ?>
    <?php if ($ogImage): ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars($metaTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDescription) ?>">
    <?php if ($ogImage): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
    <?php endif; ?>

    <!-- Favicon (사이트 설정) -->
    <?php if ($favicon): ?>
    <link rel="icon" href="<?= htmlspecialchars($favicon) ?>">
    <?php else: ?>
    <link rel="icon" href="<?= asset('/favicon.ico') ?>">
    <?php endif; ?>
    <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($appIcon ?: asset('/assets/images/app-icon.png')) ?>">

    <!-- Analytics / Pixels / Custom Head (사이트 설정) -->
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
    <?php endif; ?>
    <?php if ($naverAna): ?>
    <script type="text/javascript" src="//wcs.naver.net/wcslog.js"></script>
    <script type="text/javascript">if(!wcs_add)var wcs_add={};wcs_add["wa"]=<?= json_encode((string) $naverAna, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;if(window.wcs){wcs_do();}</script>
    <?php endif; ?>
    <?php if ($customHead): ?>
    <?= $customHead ?>
    <?php endif; ?>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Tailwind CSS Play CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'selector',
            // Tailwind 기본 .container 유틸리티 비활성화 (아래 <style>의 .container와 충돌 방지)
            corePlugins: { container: false },
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Pretendard Variable', '-apple-system', 'BlinkMacSystemFont', 'sans-serif'],
                        mono: ['JetBrains Mono', 'Pretendard Variable', 'monospace'],
                    },
                    keyframes: {
                        dotpulse: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.4' } },
                    },
                    animation: { dotpulse: 'dotpulse 2s infinite' },
                },
            },
        };
    </script>

    <style>
        /* 스크롤바/기본 폼 컨트롤이 OS가 아닌 페이지 테마를 따르도록 */
        html { color-scheme: light; scroll-padding-top: 4rem; } /* scroll-padding-top = 헤더 높이(h-16=64px), 앵커 점프 시 헤더에 안 가리도록 */
        html.dark { color-scheme: dark; }

        /* 공통 컨테이너 */
        .container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }

        /* hover 리프트/넛지 — Tailwind translate의 합성 transform(rotate/skew/scale) 회피 → 꿈틀거림 방지 */
        .lift { transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease; }
        .lift:hover { transform: translateY(-4px); }
        .arrow-nudge { transition: transform 0.2s ease, color 0.2s ease; }
        .group:hover .arrow-nudge { transform: translateX(4px); }
        /* moon-stars 글리프가 sun-fill보다 커 보여서 달만 살짝 축소 */
        .theme-toggle .bi-moon-stars { font-size: 0.9em; }

        /* 코드 블럭/터미널은 배경이 항상 다크(#0D1117) — 페이지가 라이트모드여도
           내부 스크롤바(pre overflow-x)는 다크로 고정한다(흰 스크롤바 방지). */
        .code-block, .hero-terminal { color-scheme: dark; }

        /* 코드 신택스 하이라이팅 */
        .code-block .kw  { color: #C678DD; }
        .code-block .fn  { color: #61AFEF; }
        .code-block .str { color: #98C379; }
        .code-block .var { color: #E06C75; }
        .code-block .cm  { color: #5C6370; font-style: italic; }
        .code-block .cls { color: #E5C07B; }
        .code-block .op  { color: #56B6C2; }
        .hero-terminal .prompt  { color: #34D399; }
        .hero-terminal .comment { color: #64748B; }

        /* 배경 글로우 (radial gradient ::before) */
        .glow-hero, .glow-cta { position: relative; overflow: hidden; }
        /* 히어로 글로우: 액체 블롭처럼 border-radius 모핑 + 미세 회전/스케일로 울렁임 */
        .glow-hero::before {
            content: ''; position: absolute; top: 40px; left: 50%; pointer-events: none;
            width: 560px; height: 560px;
            background: radial-gradient(circle, rgba(37,99,235,0.24) 0%, rgba(37,99,235,0.10) 55%, rgba(37,99,235,0.03) 100%);
            filter: blur(48px);
            border-radius: 50%;
            transform: translateX(-50%);
            animation: blob 16s ease-in-out infinite;
            will-change: border-radius, transform;
        }
        @keyframes blob {
            0%   { border-radius: 38% 62% 63% 37% / 41% 44% 56% 59%; transform: translate(calc(-50% + 0px), 0px)   rotate(0deg)   scale(1);    }
            25%  { border-radius: 72% 28% 48% 52% / 28% 66% 34% 72%; transform: translate(calc(-50% + 12px), -10px) rotate(72deg)  scale(1.15); }
            50%  { border-radius: 32% 68% 26% 74% / 70% 38% 62% 30%; transform: translate(calc(-50% - 10px), 9px)   rotate(180deg) scale(0.86); }
            75%  { border-radius: 66% 34% 72% 28% / 36% 72% 28% 64%; transform: translate(calc(-50% + 8px), 12px)   rotate(288deg) scale(1.1);  }
            100% { border-radius: 38% 62% 63% 37% / 41% 44% 56% 59%; transform: translate(calc(-50% + 0px), 0px)   rotate(360deg) scale(1);    }
        }
        @media (prefers-reduced-motion: reduce) {
            .glow-hero::before, .glow-cta::before { animation: none; }
        }
        .glow-cta::before {
            content: ''; position: absolute; bottom: -30px; left: 50%; pointer-events: none;
            width: 340px; height: 340px;
            background: radial-gradient(circle, rgba(37,99,235,0.18) 0%, rgba(37,99,235,0.07) 55%, rgba(37,99,235,0.02) 100%);
            filter: blur(44px);
            border-radius: 50%;
            transform: translateX(-50%);
            animation: blob 20s ease-in-out -8s infinite; /* -8s 딜레이로 히어로와 위상 어긋나게 */
            will-change: border-radius, transform;
        }

        /* 모바일 메뉴 슬라이드 */
        .mobile-menu { transform: translateY(-100%); transition: transform 0.3s ease; }
        .mobile-menu.active { transform: translateY(0); }

        /* 토스트 */
        @keyframes slideUp {
            from { opacity: 0; transform: translate(-50%, 20px); }
            to   { opacity: 1; transform: translate(-50%, 0); }
        }
        #toast.show { animation: slideUp 0.3s ease; }
    </style>

    <!-- 반복 클래스 묶음 → Tailwind 컴포넌트 레이어 (@apply) -->
    <style type="text/tailwindcss">
        @layer components {
            /* 버튼 */
            .btn         { @apply inline-flex items-center gap-2 px-7 py-3 rounded-[10px] text-[0.95rem] font-semibold transition-[transform,background-color,box-shadow,border-color] duration-200; }
            .btn:hover   { transform: translateY(-3px); }
            .btn-primary { @apply bg-blue-600 text-white shadow-[0_4px_14px_rgba(37,99,235,0.35)] hover:bg-blue-700; }
            .btn-outline { @apply bg-transparent text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-600 hover:border-blue-600 hover:text-blue-600; }
            .btn-accent  { @apply bg-emerald-500 text-white shadow-[0_4px_14px_rgba(16,185,129,0.35)] hover:bg-emerald-600; }

            /* 네비게이션 링크 */
            .nav-link { @apply text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 transition-colors; }
            .m-link   { @apply block px-4 py-3 text-slate-600 dark:text-slate-400 font-medium rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 hover:text-slate-900 dark:hover:text-slate-100 transition-all; }

            /* 섹션 */
            .section       { @apply py-16 md:py-[100px]; }
            .section-alt   { @apply bg-slate-50 dark:bg-slate-800; }
            .section-head  { @apply text-center mb-16; }
            .section-label { @apply inline-block text-[0.8rem] font-semibold uppercase tracking-[0.1em] text-blue-500 mb-3; }
            .section-title { @apply text-[1.7rem] md:text-[2.2rem] font-extrabold leading-[1.3] tracking-[-0.02em] mb-4; }
            .section-desc  { @apply text-base md:text-[1.1rem] text-slate-600 dark:text-slate-400 max-w-[600px] mx-auto; }

            /* 카드 / 리스트 */
            .card      { @apply bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl; }
            .code-feat { @apply flex items-center gap-2.5 text-slate-600 dark:text-slate-400 text-[0.95rem]; }

            /* 아키텍처 다이어그램 ('arch-layer'는 스크롤 애니메이션 JS 훅도 겸함) */
            .arch-layer { @apply relative p-8 border border-slate-200 dark:border-slate-700 rounded-2xl mb-6 transition-colors hover:border-blue-600; }
            .arch-label { @apply absolute -top-2.5 left-6 bg-slate-50 dark:bg-slate-800 px-3 text-[0.75rem] font-semibold uppercase tracking-[0.1em] text-blue-500; }
            .arch-tag   { @apply px-4 py-2 border rounded-lg text-[0.8rem] md:text-[0.85rem] font-medium; }
            .arch-arrow { @apply text-center text-[1.4rem] text-slate-400 dark:text-slate-500 my-5 relative z-[1]; }

            /* 비교 테이블 셀 */
            .cmp-cell      { @apply px-4 py-3 md:px-6 md:py-4 text-left border-b border-slate-200 dark:border-slate-700; }
            .cmp-cell-last { @apply px-4 py-3 md:px-6 md:py-4 text-left; }
        }
    </style>
</head>
<body class="font-sans bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-0 focus:left-0 focus:z-[1000] bg-blue-600 text-white px-4 py-2 rounded-br-lg">Skip to main content</a>

<!-- Header -->
<header class="fixed top-0 left-0 right-0 h-16 z-[100] bg-white/85 dark:bg-slate-900/85 backdrop-blur-md border-b border-slate-200 dark:border-slate-700">
    <div class="container flex items-center justify-between h-full">
        <a href="/" class="text-2xl font-extrabold tracking-tight text-slate-800 dark:text-slate-100" aria-label="MUBLO 홈">MU<span class="text-blue-500">BLO</span></a>

        <nav class="hidden md:flex items-center gap-8" aria-label="메인 네비게이션">
            <a href="#features" class="nav-link">특징</a>
            <a href="#code" class="nav-link">코드</a>
            <a href="#comparison" class="nav-link">비교</a>
            <a href="#architecture" class="nav-link">아키텍처</a>
            <a href="https://github.com/wwiz-mublo/mublo/tree/main/docs" target="_blank" class="nav-link">문서</a>
        </nav>

        <div class="flex items-center gap-3">
            <button class="theme-toggle border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 w-9 h-9 rounded-lg flex items-center justify-center text-lg hover:border-blue-600 hover:text-blue-600 transition-all" onclick="toggleTheme()" title="테마 전환" aria-label="테마 전환">
                <i class="bi bi-sun-fill" aria-hidden="true"></i>
            </button>
            <a href="https://github.com/wwiz-mublo/mublo" target="_blank" class="hidden md:flex items-center gap-1.5 h-9 px-4 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-700 rounded-lg text-slate-800 dark:text-slate-100 text-[0.85rem] font-medium hover:bg-transparent hover:border-blue-600 hover:text-blue-600 dark:hover:text-blue-400 dark:hover:border-blue-500 transition-all" aria-label="GitHub 저장소">
                <i class="bi bi-github" aria-hidden="true"></i> GitHub
            </a>
            <button class="mobile-toggle md:hidden text-slate-800 dark:text-slate-100 text-2xl p-2" onclick="toggleMobile()" aria-label="메뉴 열기" aria-expanded="false">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</header>

<!-- Mobile Menu -->
<div class="mobile-menu md:hidden fixed top-16 left-0 right-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 p-5 z-[99] shadow-lg" id="mobileMenu" aria-label="모바일 네비게이션">
    <a href="#features" onclick="toggleMobile()" class="m-link">특징</a>
    <a href="#code" onclick="toggleMobile()" class="m-link">코드</a>
    <a href="#comparison" onclick="toggleMobile()" class="m-link">비교</a>
    <a href="#architecture" onclick="toggleMobile()" class="m-link">아키텍처</a>
    <a href="https://github.com/wwiz-mublo/mublo/tree/main/docs" target="_blank" onclick="toggleMobile()" class="m-link">문서</a>
    <a href="https://github.com/wwiz-mublo/mublo" target="_blank" onclick="toggleMobile()" class="flex items-center justify-center gap-1.5 mx-4 my-2 px-4 py-2 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-700 rounded-lg text-slate-800 dark:text-slate-100 text-[0.85rem] font-medium">
        <i class="bi bi-github" aria-hidden="true"></i> GitHub
    </a>
</div>

<main id="main-content">

<!-- Hero -->
<section class="glow-hero pt-[144px] pb-20 text-center">
    <div class="container relative z-[1]">
        <div class="inline-flex items-center gap-2 px-4 py-1.5 bg-blue-600/10 border border-blue-600/20 rounded-full font-mono text-[0.85rem] text-blue-500 mb-8" role="status">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-dotpulse" aria-hidden="true"></span>
            v<?= \Mublo\Core\App\Application::VERSION ?> Released
        </div>

        <h1 class="text-[2.25rem] md:text-[3.75rem] font-extrabold leading-[1.125] tracking-[-0.03em] mb-6">
            PHP 웹 플랫폼,<br>
            <span class="bg-gradient-to-br from-blue-500 to-emerald-500 bg-clip-text text-transparent">다시 설계하다</span>
        </h1>

        <p class="text-base md:text-[1.2rem] text-slate-600 dark:text-slate-400 max-w-[560px] mx-auto mb-10 leading-[1.8]">
            Core는 가볍게, 확장은 자유롭게.<br>
            멀티 도메인 · 블록 시스템 · Plugin + Package 아키텍처
        </p>

        <div class="flex flex-col md:flex-row justify-center items-center gap-4 mb-10">
            <a href="/admin" target="_blank" rel="noopener" class="btn btn-primary">
                <i class="bi bi-display" aria-hidden="true"></i> 관리자 데모
            </a>
            <a href="https://github.com/wwiz-mublo/mublo/archive/refs/heads/main.zip" class="btn btn-outline">
                <i class="bi bi-download" aria-hidden="true"></i> 다운로드
            </a>
            <a href="https://github.com/wwiz-mublo/mublo/blob/main/docs/user-guide/installation.md" target="_blank" class="btn btn-outline">
                <i class="bi bi-book" aria-hidden="true"></i> 설치 가이드
            </a>
        </div>
        <p class="mt-2 text-[0.85rem] text-slate-400 dark:text-slate-500">데모 계정 &mdash; ID: <code class="text-slate-600 dark:text-slate-400">admin</code> / PW: <code class="text-slate-600 dark:text-slate-400">12341234</code></p>

        <div class="hero-terminal max-w-[540px] mx-auto mt-10 bg-[#0D1117] border border-slate-700 rounded-xl overflow-hidden text-left" aria-label="설치 명령어 예시">
            <div class="flex items-center gap-1.5 px-4 py-3 bg-black/30 border-b border-slate-700" aria-hidden="true">
                <span class="w-2.5 h-2.5 rounded-full bg-[#FF5F57]"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-[#FEBC2E]"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-[#28C840]"></span>
            </div>
            <pre class="p-5 font-mono text-[0.9rem] text-[#E2E8F0] overflow-x-auto"><span class="comment"># 다운로드 & 의존성 설치</span>
<span class="prompt">$</span> unzip mublo-main.zip && cd mublo-main
<span class="prompt">$</span> composer install --no-dev

<span class="comment"># 브라우저에서 설치 마법사 실행</span>
<span class="prompt">></span> https://your-domain.com/install</pre>
        </div>

        <?php if ($siteLinks): ?>
        <!-- 설치해서 실제로 돌아가는 사이트들 (데이터: storage/custom/landing.json) -->
        <div class="mt-14">
        <?php if ($siteLinksTitle): ?>
        <p class="mb-5 text-[0.8rem] font-semibold uppercase tracking-[0.1em] text-slate-400 dark:text-slate-500">
            <?php if ($siteLinksTitleLeadingIcon): ?><i class="bi <?= htmlspecialchars($siteLinksTitleLeadingIcon) ?>" aria-hidden="true"></i> <?php endif; ?><?= htmlspecialchars($siteLinksTitle) ?><?php if ($siteLinksTitleTrailingIcon): ?> <i class="bi <?= htmlspecialchars($siteLinksTitleTrailingIcon) ?>" aria-hidden="true"></i><?php endif; ?>
        </p>
        <?php endif; ?>
        <div class="flex flex-wrap items-start justify-center gap-4 max-w-5xl mx-auto">
            <?php foreach ($siteLinks as $link): ?>
            <?php $hasDemo = !empty($link['demoUrl']) || !empty($link['demoId']) || !empty($link['demoPw']); ?>
            <div class="w-full sm:w-[260px] text-left">
                <a href="<?= htmlspecialchars($link['url'] ?? '#') ?>"<?= !empty($link['target']) ? ' target="' . htmlspecialchars($link['target']) . '" rel="noopener"' : '' ?>
                   class="group card lift flex items-center gap-4 p-5 hover:border-blue-600 hover:shadow-xl">
                    <?php if (!empty($link['icon'])): ?>
                    <span class="shrink-0 w-11 h-11 flex items-center justify-center rounded-xl bg-blue-600/15 text-blue-500 text-xl">
                        <i class="bi <?= htmlspecialchars($link['icon']) ?>" aria-hidden="true"></i>
                    </span>
                    <?php endif; ?>
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($link['label'] ?? '') ?></span>
                        <?php if (!empty($link['desc'])): ?>
                        <span class="block text-sm text-slate-500 dark:text-slate-400 truncate"><?= htmlspecialchars($link['desc']) ?></span>
                        <?php endif; ?>
                    </span>
                    <i class="bi bi-arrow-right arrow-nudge text-slate-400 group-hover:text-blue-500" aria-hidden="true"></i>
                </a>
                <?php if ($hasDemo): ?>
                <div class="mt-1.5 px-8 font-mono text-xs text-slate-400 dark:text-slate-500">
                    <?php if (!empty($link['demoUrl'])): ?><div class="truncate"><i class="bi bi-gear" aria-hidden="true"></i> <?= htmlspecialchars($link['demoUrl']) ?></div><?php endif; ?>
                    <?php if (!empty($link['demoId']) || !empty($link['demoPw'])): ?>
                    <div>
                        <?php if (!empty($link['demoId'])): ?>ID: <span class="select-all text-slate-600 dark:text-slate-400"><?= htmlspecialchars($link['demoId']) ?></span><?php endif; ?><?php if (!empty($link['demoId']) && !empty($link['demoPw'])): ?> · <?php endif; ?><?php if (!empty($link['demoPw'])): ?>PW: <span class="select-all text-slate-600 dark:text-slate-400"><?= htmlspecialchars($link['demoPw']) ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Features -->
<section class="section section-alt" id="features" aria-labelledby="features-title">
    <div class="container">
        <div class="section-head">
            <span class="section-label">Core Features</span>
            <h2 class="section-title" id="features-title">하나의 코드베이스, 무한한 가능성</h2>
            <p class="section-desc">
                MUBLO는 단순한 프레임워크가 아닌 플랫폼입니다.
                Core 위에 Plugin과 Package를 조합하여 어떤 웹서비스든 구축할 수 있습니다.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <div class="feature-card card lift p-9 hover:border-blue-600 hover:shadow-2xl">
                <div class="w-12 h-12 flex items-center justify-center rounded-xl text-[1.4rem] mb-5 bg-blue-600/15 text-blue-500" aria-hidden="true"><i class="bi bi-globe2"></i></div>
                <h3 class="text-[1.2rem] font-bold mb-3">멀티 도메인</h3>
                <p class="text-slate-600 dark:text-slate-400 text-[0.95rem] leading-[1.7]">하나의 코드베이스로 여러 사이트를 운영합니다. 도메인별 테마, 회원, 권한을 독립적으로 관리하세요.</p>
            </div>
            <div class="feature-card card lift p-9 hover:border-blue-600 hover:shadow-2xl">
                <div class="w-12 h-12 flex items-center justify-center rounded-xl text-[1.4rem] mb-5 bg-emerald-500/15 text-emerald-500" aria-hidden="true"><i class="bi bi-grid-1x2"></i></div>
                <h3 class="text-[1.2rem] font-bold mb-3">블록 시스템</h3>
                <p class="text-slate-600 dark:text-slate-400 text-[0.95rem] leading-[1.7]">코드 수정 없이 관리자에서 페이지를 구성합니다. 게시판, 배너, 갤러리 등 다양한 블록을 배치하고 스킨을 선택하세요.</p>
            </div>
            <div class="feature-card card lift p-9 hover:border-blue-600 hover:shadow-2xl">
                <div class="w-12 h-12 flex items-center justify-center rounded-xl text-[1.4rem] mb-5 bg-violet-500/15 text-violet-400" aria-hidden="true"><i class="bi bi-puzzle"></i></div>
                <h3 class="text-[1.2rem] font-bold mb-3">Plugin + Package</h3>
                <p class="text-slate-600 dark:text-slate-400 text-[0.95rem] leading-[1.7]">Plugin으로 Core를 확장하고, Package로 독립 애플리케이션을 추가합니다. 이벤트 시스템으로 느슨하게 결합합니다.</p>
            </div>
        </div>
    </div>
</section>

<!-- Code Preview -->
<section class="section" id="code" aria-labelledby="code-title">
    <div class="container">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <div>
                <h3 class="text-[1.1rem] text-slate-400 dark:text-slate-500 font-medium mb-2" id="code-title">Modern PHP</h3>
                <h2 class="text-[2rem] font-extrabold mb-5 tracking-[-0.02em] leading-tight">구조적이면서<br>쉽게 확장 가능한</h2>
                <p class="text-slate-600 dark:text-slate-400 mb-8 leading-[1.8]">
                    PSR-4 오토로딩, DI 컨테이너, 이벤트 시스템 위에 구축되었습니다.
                    Controller, Service, Repository 계층으로 책임을 명확히 분리합니다.
                </p>
                <ul class="flex flex-col gap-3">
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> PHP 8.2+ & PSR-4 표준</li>
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> Dependency Injection</li>
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> Event-Driven Architecture</li>
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> Result 객체 패턴</li>
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> AES-256-GCM 암호화</li>
                </ul>
            </div>

            <div class="code-block bg-[#0D1117] border border-slate-700 rounded-xl overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 bg-black/30 border-b border-slate-700">
                    <span class="font-mono text-[0.8rem] text-slate-400 dark:text-slate-500">ProductController.php</span>
                    <button class="code-block-copy bg-transparent border-0 text-slate-400 dark:text-slate-500 cursor-pointer text-[0.9rem] p-1 hover:text-slate-100 transition-colors" title="코드 복사" aria-label="코드 복사">
                        <i class="bi bi-clipboard" aria-hidden="true"></i>
                    </button>
                </div>
                <pre class="p-6 font-mono text-[0.85rem] leading-[1.8] text-[#E2E8F0] overflow-x-auto"><span class="kw">class</span> <span class="cls">ProductController</span>
{
    <span class="kw">public function</span> <span class="fn">list</span>(
        <span class="cls">Request</span> <span class="var">$request</span>,
        <span class="cls">Context</span> <span class="var">$context</span>
    ): <span class="cls">ViewResponse</span> {
        <span class="var">$result</span> = <span class="var">$this</span>-><span class="var">productService</span>
            -><span class="fn">getList</span>(<span class="var">$context</span>-><span class="fn">getDomainId</span>(), <span class="var">$filters</span>);

        <span class="kw">return</span> <span class="cls">ViewResponse</span>::<span class="fn">view</span>(<span class="str">'Product/List'</span>)
            -><span class="fn">withData</span>(<span class="var">$result</span>-><span class="fn">getData</span>());
    }

    <span class="kw">public function</span> <span class="fn">create</span>(
        <span class="cls">Request</span> <span class="var">$request</span>
    ): <span class="cls">JsonResponse</span> {
        <span class="var">$result</span> = <span class="var">$this</span>-><span class="var">productService</span>
            -><span class="fn">store</span>(<span class="var">$request</span>-><span class="fn">input</span>(<span class="str">'formData'</span>));

        <span class="kw">return</span> <span class="var">$result</span>-><span class="fn">isSuccess</span>()
            ? <span class="cls">JsonResponse</span>::<span class="fn">success</span>(<span class="var">$result</span>-><span class="fn">getData</span>())
            : <span class="cls">JsonResponse</span>::<span class="fn">error</span>(<span class="var">$result</span>-><span class="fn">getMessage</span>());
    }
}</pre>
            </div>
        </div>
    </div>
</section>

<!-- Comparison -->
<section class="section section-alt" id="comparison" aria-labelledby="comparison-title">
    <div class="container">
        <div class="section-head">
            <span class="section-label">Comparison</span>
            <h2 class="section-title" id="comparison-title">구조적이면서 CMS이다</h2>
            <p class="section-desc">기존 CMS의 편의성과 프레임워크의 구조를 동시에 갖추고 있습니다.</p>
        </div>

        <table class="w-full border-separate border-spacing-0 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <caption class="sr-only">MUBLO와 기존 PHP CMS 기능 비교표</caption>
            <thead>
                <tr>
                    <th class="cmp-cell bg-slate-100 dark:bg-slate-700 text-[0.85rem] font-semibold uppercase tracking-[0.05em] text-slate-400 dark:text-slate-500" scope="col">기능</th>
                    <th class="cmp-cell bg-slate-100 dark:bg-slate-700 text-[0.85rem] font-semibold uppercase tracking-[0.05em] text-slate-400 dark:text-slate-500" scope="col">기존 PHP CMS</th>
                    <th class="cmp-cell bg-slate-100 dark:bg-slate-700 text-[0.85rem] font-semibold uppercase tracking-[0.05em] text-slate-400 dark:text-slate-500" scope="col">MUBLO</th>
                </tr>
            </thead>
            <tbody>
                <tr class="bg-emerald-500/5">
                    <th class="cmp-cell font-semibold text-[0.95rem]" scope="row">메인화면 수정</th>
                    <td class="cmp-cell text-[0.95rem] text-slate-400 dark:text-slate-500">PHP 코드 직접 수정</td>
                    <td class="cmp-cell text-[0.95rem] text-emerald-500 font-medium"><i class="bi bi-check-lg" aria-hidden="true"></i> 관리자에서 블록 편집</td>
                </tr>
                <tr>
                    <th class="cmp-cell font-semibold text-[0.95rem]" scope="row">멀티 사이트</th>
                    <td class="cmp-cell text-[0.95rem] text-slate-400 dark:text-slate-500">사이트마다 별도 설치</td>
                    <td class="cmp-cell text-[0.95rem] text-emerald-500 font-medium"><i class="bi bi-check-lg" aria-hidden="true"></i> 하나의 코드베이스</td>
                </tr>
                <tr class="bg-emerald-500/5">
                    <th class="cmp-cell font-semibold text-[0.95rem]" scope="row">기능 확장</th>
                    <td class="cmp-cell text-[0.95rem] text-slate-400 dark:text-slate-500">Core 코드 직접 수정</td>
                    <td class="cmp-cell text-[0.95rem] text-emerald-500 font-medium"><i class="bi bi-check-lg" aria-hidden="true"></i> Plugin / Package 분리</td>
                </tr>
                <tr>
                    <th class="cmp-cell font-semibold text-[0.95rem]" scope="row">아키텍처</th>
                    <td class="cmp-cell text-[0.95rem] text-slate-400 dark:text-slate-500">절차적 / 레거시</td>
                    <td class="cmp-cell text-[0.95rem] text-emerald-500 font-medium"><i class="bi bi-check-lg" aria-hidden="true"></i> PSR-4, DI, Event</td>
                </tr>
                <tr class="bg-emerald-500/5">
                    <th class="cmp-cell-last font-semibold text-[0.95rem]" scope="row">비개발자 운영</th>
                    <td class="cmp-cell-last text-[0.95rem] text-slate-400 dark:text-slate-500">제한적</td>
                    <td class="cmp-cell-last text-[0.95rem] text-emerald-500 font-medium"><i class="bi bi-check-lg" aria-hidden="true"></i> 블록 + 관리자 UI</td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<!-- Stats -->
<section class="section" id="stats" aria-labelledby="stats-title">
    <div class="container">
        <div class="section-head">
            <span class="section-label">By the Numbers</span>
            <h2 class="section-title" id="stats-title">숫자로 보는 MUBLO</h2>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-8">
            <div class="stat-card card text-center px-6 py-10 transition-colors hover:border-blue-600">
                <div class="text-[2rem] md:text-[2.8rem] font-extrabold bg-gradient-to-br from-blue-500 to-emerald-500 bg-clip-text text-transparent mb-2 leading-tight">4</div>
                <div class="text-[0.95rem] text-slate-600 dark:text-slate-400 font-medium">핵심 시스템</div>
            </div>
            <div class="stat-card card text-center px-6 py-10 transition-colors hover:border-blue-600">
                <div class="text-[2rem] md:text-[2.8rem] font-extrabold bg-gradient-to-br from-blue-500 to-emerald-500 bg-clip-text text-transparent mb-2 leading-tight"><?= $pluginCount ?></div>
                <div class="text-[0.95rem] text-slate-600 dark:text-slate-400 font-medium">Plugins</div>
            </div>
            <div class="stat-card card text-center px-6 py-10 transition-colors hover:border-blue-600">
                <div class="text-[2rem] md:text-[2.8rem] font-extrabold bg-gradient-to-br from-blue-500 to-emerald-500 bg-clip-text text-transparent mb-2 leading-tight"><?= $packageCount ?></div>
                <div class="text-[0.95rem] text-slate-600 dark:text-slate-400 font-medium">Packages</div>
            </div>
            <div class="stat-card card text-center px-6 py-10 transition-colors hover:border-blue-600">
                <div class="text-[2rem] md:text-[2.8rem] font-extrabold bg-gradient-to-br from-blue-500 to-emerald-500 bg-clip-text text-transparent mb-2 leading-tight">PHP 8.2</div>
                <div class="text-[0.95rem] text-slate-600 dark:text-slate-400 font-medium">최소 요구사항</div>
            </div>
        </div>
    </div>
</section>

<!-- Architecture -->
<section class="section section-alt" id="architecture" aria-labelledby="architecture-title">
    <div class="container">
        <div class="section-head">
            <span class="section-label">Architecture</span>
            <h2 class="section-title" id="architecture-title">계층 구조 한눈에 보기</h2>
            <p class="section-desc">
                Core 위에 Plugin과 Package가 이벤트 시스템으로 연결됩니다.
                각 계층은 독립적으로 개발하고 배포할 수 있습니다.
            </p>
        </div>

        <div class="max-w-[800px] mx-auto">
            <div class="arch-layer">
                <span class="arch-label">Core</span>
                <div class="flex gap-2 md:gap-4 flex-wrap">
                    <span class="arch-tag bg-blue-600/10 border-blue-600/20 text-blue-500">Router</span>
                    <span class="arch-tag bg-blue-600/10 border-blue-600/20 text-blue-500">DI Container</span>
                    <span class="arch-tag bg-blue-600/10 border-blue-600/20 text-blue-500">Event System</span>
                    <span class="arch-tag bg-blue-600/10 border-blue-600/20 text-blue-500">Auth & Session</span>
                    <span class="arch-tag bg-blue-600/10 border-blue-600/20 text-blue-500">Database</span>
                    <span class="arch-tag bg-blue-600/10 border-blue-600/20 text-blue-500">Cache</span>
                    <span class="arch-tag bg-blue-600/10 border-blue-600/20 text-blue-500">View Renderer</span>
                    <span class="arch-tag bg-blue-600/10 border-blue-600/20 text-blue-500">Block System</span>
                </div>
            </div>

            <div class="arch-arrow">
                <i class="bi bi-arrow-down-up" aria-hidden="true"></i>
                <span class="text-[0.75rem] block -mt-1">Event System</span>
            </div>

            <div class="arch-layer">
                <span class="arch-label">Plugins (Core 확장)</span>
                <div class="flex gap-2 md:gap-4 flex-wrap">
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500">Banner</span>
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500">FAQ</span>
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500">Widget</span>
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500">Popup</span>
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500">MemberPoint</span>
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500">VisitorStats</span>
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500">SnsLogin</span>
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500">Survey</span>
                    <span class="arch-tag bg-emerald-500/10 border-emerald-500/20 text-emerald-500 border-dashed opacity-70 hover:opacity-100 cursor-pointer transition-opacity">+ 직접 만들기</span>
                </div>
            </div>

            <div class="arch-arrow">
                <i class="bi bi-arrow-down-up" aria-hidden="true"></i>
                <span class="text-[0.75rem] block -mt-1">Event System</span>
            </div>

            <div class="arch-layer">
                <span class="arch-label">Packages (독립 애플리케이션)</span>
                <div class="flex gap-2 md:gap-4 flex-wrap">
                    <span class="arch-tag bg-violet-500/10 border-violet-500/20 text-violet-400">Board (게시판)</span>
                    <span class="arch-tag bg-violet-500/10 border-violet-500/20 text-violet-400">Shop (쇼핑몰)</span>
                    <span class="arch-tag bg-violet-500/10 border-violet-500/20 text-violet-400 border-dashed opacity-70 hover:opacity-100 cursor-pointer transition-opacity">+ 직접 만들기</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Open Ecosystem -->
<section class="section" id="ecosystem" aria-labelledby="ecosystem-title">
    <div class="container">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <div>
                <h3 class="text-[1.1rem] text-slate-400 dark:text-slate-500 font-medium mb-2" id="ecosystem-title">Open Ecosystem</h3>
                <h2 class="text-[2rem] font-extrabold mb-5 tracking-[-0.02em] leading-tight">누구나 만들고,<br>누구나 배포할 수 있습니다</h2>
                <p class="text-slate-600 dark:text-slate-400 mb-8 leading-[1.8]">
                    Plugin과 Package는 독립적인 구조를 가지며, 이벤트 시스템으로 Core와 연결됩니다.
                    표준 인터페이스만 따르면 누구든 자신만의 확장을 개발하고 배포할 수 있습니다.
                </p>
                <ul class="flex flex-col gap-3">
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> Provider 하나로 자동 등록</li>
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> 독립 MVC 구조 (Controller / Service / Repository)</li>
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> 자체 마이그레이션 & 관리자 메뉴</li>
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> 이벤트 구독으로 Core 기능 확장</li>
                    <li class="code-feat"><i class="bi bi-check-circle-fill text-emerald-500" aria-hidden="true"></i> 블록 렌더러 연동 (메인 페이지 노출)</li>
                </ul>
            </div>

            <div class="code-block bg-[#0D1117] border border-slate-700 rounded-xl overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 bg-black/30 border-b border-slate-700">
                    <span class="font-mono text-[0.8rem] text-slate-400 dark:text-slate-500">MyPlugin/MyPluginProvider.php</span>
                    <button class="code-block-copy bg-transparent border-0 text-slate-400 dark:text-slate-500 cursor-pointer text-[0.9rem] p-1 hover:text-slate-100 transition-colors" title="코드 복사" aria-label="코드 복사">
                        <i class="bi bi-clipboard" aria-hidden="true"></i>
                    </button>
                </div>
                <pre class="p-6 font-mono text-[0.85rem] leading-[1.8] text-[#E2E8F0] overflow-x-auto"><span class="kw">class</span> <span class="cls">MyPluginProvider</span> <span class="kw">implements</span> <span class="cls">PluginProviderInterface</span>
{
    <span class="kw">public function</span> <span class="fn">register</span>(<span class="cls">DependencyContainer</span> <span class="var">$c</span>): <span class="cls">void</span>
    {
        <span class="cm">// 서비스 등록</span>
        <span class="var">$c</span>-><span class="fn">singleton</span>(<span class="cls">MyService</span>::<span class="kw">class</span>);
    }

    <span class="kw">public function</span> <span class="fn">boot</span>(<span class="cls">DependencyContainer</span> <span class="var">$c</span>, <span class="cls">Context</span> <span class="var">$ctx</span>): <span class="cls">void</span>
    {
        <span class="cm">// 이벤트 구독</span>
        <span class="var">$c</span>-><span class="fn">get</span>(<span class="cls">EventDispatcher</span>::<span class="kw">class</span>)
            -><span class="fn">addSubscriber</span>(<span class="kw">new</span> <span class="cls">MySubscriber</span>());
    }
}</pre>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="glow-cta text-center py-[100px]" aria-labelledby="cta-title">
    <div class="container relative z-[1]">
        <h2 class="text-[2.5rem] font-extrabold mb-4 tracking-[-0.02em]" id="cta-title">지금 시작하세요!</h2>
        <p class="text-[1.1rem] text-slate-600 dark:text-slate-400 mb-10">관리자 데모를 먼저 확인하거나, 바로 다운로드해서 5분이면 시작할 수 있습니다.</p>
        <div class="flex flex-col md:flex-row justify-center gap-4">
            <a href="/admin" target="_blank" rel="noopener" class="btn btn-accent">
                <i class="bi bi-display" aria-hidden="true"></i> 관리자 데모
            </a>
            <a href="https://github.com/wwiz-mublo/mublo/archive/refs/heads/main.zip" class="btn btn-outline">
                <i class="bi bi-download" aria-hidden="true"></i> 다운로드
            </a>
        </div>
        <p class="mt-3 text-[0.85rem] text-slate-400 dark:text-slate-500">데모 계정 &mdash; ID: <code class="text-slate-600 dark:text-slate-400">admin</code> / PW: <code class="text-slate-600 dark:text-slate-400">12341234</code></p>
    </div>
</section>

</main>

<!-- Footer -->
<footer class="py-12 border-t border-slate-200 dark:border-slate-700">
    <div class="container flex flex-col md:flex-row items-center justify-between gap-4 md:gap-0 text-center md:text-left">
        <div class="text-slate-400 dark:text-slate-500 text-[0.85rem]">
            &copy; 2026 MUBLO. Released under the <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener" class="text-blue-500 hover:text-emerald-500">MIT License</a>.
        </div>
        <div class="flex gap-6">
            <a href="https://github.com/wwiz-mublo/mublo/tree/main/docs" target="_blank" class="text-slate-400 dark:text-slate-500 text-[0.85rem] hover:text-slate-900 dark:hover:text-slate-100">문서</a>
            <a href="https://github.com/wwiz-mublo/mublo/issues" target="_blank" class="text-slate-400 dark:text-slate-500 text-[0.85rem] hover:text-slate-900 dark:hover:text-slate-100">이슈</a>
            <a href="https://github.com/wwiz-mublo/mublo" target="_blank" class="text-slate-400 dark:text-slate-500 text-[0.85rem] hover:text-slate-900 dark:hover:text-slate-100">GitHub</a>
        </div>
    </div>
</footer>

<div id="toast" class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-100 px-6 py-3 rounded-full border border-slate-200 dark:border-slate-700 shadow-lg z-[1000] text-[0.95rem]" role="alert" aria-live="assertive" aria-atomic="true"></div>

<script>
    // Theme Toggle
    function toggleTheme() {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('mublo-theme', isDark ? 'dark' : 'light');
        const icon = document.querySelector('.theme-toggle i');
        icon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-stars';
    }

    // Load saved theme (기본: dark)
    (function() {
        const saved = localStorage.getItem('mublo-theme');
        if (saved === 'light') {
            document.documentElement.classList.remove('dark');
        } else if (saved === 'dark') {
            document.documentElement.classList.add('dark');
        }
        const icon = document.querySelector('.theme-toggle i');
        if (icon) icon.className = document.documentElement.classList.contains('dark') ? 'bi bi-sun-fill' : 'bi bi-moon-stars';
    })();

    // Mobile Menu Toggle
    function toggleMobile() {
        const menu = document.getElementById('mobileMenu');
        const toggleBtn = document.querySelector('.mobile-toggle');
        const isActive = menu.classList.contains('active');
        menu.classList.toggle('active');
        toggleBtn.setAttribute('aria-expanded', isActive ? 'false' : 'true');
        toggleBtn.setAttribute('aria-label', isActive ? '메뉴 열기' : '메뉴 닫기');
    }

    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
        const menu = document.getElementById('mobileMenu');
        const toggleBtn = document.querySelector('.mobile-toggle');
        if (menu.classList.contains('active') &&
            !menu.contains(event.target) &&
            !toggleBtn.contains(event.target)) {
            menu.classList.remove('active');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.setAttribute('aria-label', '메뉴 열기');
        }
    });

    // Scroll animation
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.feature-card, .stat-card, .arch-layer').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });

    // Copy code
    document.querySelectorAll('.code-block-copy').forEach(btn => {
        btn.addEventListener('click', () => {
            const code = btn.closest('.code-block').querySelector('pre').textContent;
            navigator.clipboard.writeText(code).then(() => {
                btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 2000);
            });
        });
    });

    // 인페이지 앵커: 주소창에 #해시 안 남기고 부드럽게 스크롤 (헤더 높이는 scroll-padding-top이 보정)
    document.querySelectorAll('.nav-link[href^="#"], .m-link[href^="#"]').forEach(a => {
        a.addEventListener('click', (e) => {
            const target = document.getElementById(a.getAttribute('href').slice(1));
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth' });
        });
    });
</script>
</body>
</html>
