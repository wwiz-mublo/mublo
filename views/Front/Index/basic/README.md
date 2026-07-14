# Index 스킨 개발 안내

Index 스킨은 루트 경로(`/`)의 본문을 담당합니다. 일반 사이트 프레임 안에서 본문만 출력할 수도 있고, 프레임을 완전히 벗어나 독립된 HTML 문서를 만들 수도 있습니다.

> **사용 범위:** 이 문서의 `chromeless` 모드는 반드시 Index 스킨에서만 사용할 수 있습니다. Core도 `index/*` ViewResponse에서만 이 모드를 허용하며, 회원·인증·검색·Plugin·Package 등 다른 Front 화면에서 선언하면 무시하고 기존 일반 프레임을 조립합니다.

운영 중인 `basic` 파일을 직접 수정하기보다 다음 구조로 새 스킨을 만드는 것을 권장합니다.

```text
views/Front/Index/
├─ basic/
│  └─ Index.php
└─ my-index/
   └─ Index.php
```

관리자에서 `사이트 설정 → 테마 설정 → 메인(Index)`으로 이동하면 `views/Front/Index` 아래의 스킨 디렉터리가 자동으로 선택 목록에 표시됩니다. Linux 환경은 파일명의 대소문자를 구분하므로 `Front/Index/{스킨명}/Index.php` 표기를 지켜야 합니다.

## 일반 Index 스킨

별도의 레이아웃 선언이 없으면 Core가 사이트 프레임과 블록 위치를 조립합니다. 기본 스킨은 실제로 선택되어 실행될 때 `index` 블록을 지연 렌더링합니다.

```php
<?php
$domainId = (int) $mublo['site']['domainId'];
$blockHtml = \Mublo\Helper\BlockHelper::index($domainId);
?>
<main class="page-index">
    <?= $blockHtml ?>
</main>
```

## Header와 Footer만 제어하기

스킨 상단에서 사이트 Header와 Footer의 사용 여부를 선언할 수 있습니다.

```php
<?php
$this->layout([
    'header' => false,
    'footer' => false,
    'layout' => 'full',
]);
?>
```

이 방식은 Core의 `Head.php`, `Foot.php`, 본문 Layout을 계속 사용합니다. 또한 `contenthead`와 `contentfoot` 같은 본문 블록 위치는 계속 렌더링되므로 블록 시스템 전체를 우회하는 용도로는 적합하지 않습니다.

## Head와 Foot만 사용하는 페이지(chromeless)

Core의 SEO·공통 CSS·공통 JS 골격은 유지하면서 사이트 Header, Footer, Layout과 모든 블록 위치를 제외하려면 다음 모드를 선언합니다.

`chromeless`는 `views/Front/Index/{스킨명}/Index.php` 전용입니다. 다른 Front 뷰나 절대 경로 Plugin/Package 뷰에는 적용되지 않습니다.

```php
<?php
$this->layout(['mode' => 'chromeless']);
?>

<main class="my-index">
    Head.php와 Foot.php 사이에 이 내용만 출력됩니다.
</main>
```

출력 순서는 `Head.php → 선택한 Index.php → Foot.php`입니다. 스킨을 선택하면 해당 파일의 선언이 자동 적용되며 별도 사이트 설정 변경은 필요하지 않습니다.

## 독립 페이지(standalone) 만들기

스킨이 `<!DOCTYPE html>`부터 문서 전체를 직접 출력하려면 가장 먼저 `standalone`을 선언합니다.

```php
<?php
$this->layout(['standalone' => true]);

$site = $mublo['site'];
$viewer = $mublo['viewer'];
$csrfToken = $mublo['security']['csrfToken'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title><?= htmlspecialchars($site['config']['site_title'] ?? '') ?></title>

    <!-- MUBLO_CSS -->
</head>
<body>
    <main>
        독립 메인 페이지
    </main>

    <!-- MUBLO_JS -->
</body>
</html>
```

`standalone` 스킨을 선택하면 Core의 다음 프레임 요소는 출력되지 않습니다.

- `Head.php`와 `Foot.php`
- 사이트 Header와 Footer
- 본문 Layout과 사이드바
- `topbar`, `subhead`, `contenthead`, `contentfoot`, `subfoot` 블록 위치

스킨이 자체 Header나 Footer를 출력하는 것은 자유입니다. `views/Front/Index/mublo/Index.php`가 전체 standalone 구현 예제입니다.

## 사용할 수 있는 Core 데이터

Index 스킨에는 프론트 뷰 계약인 `$mublo`가 자동으로 전달됩니다. 원시 세션이나 Entity 대신 우선 이 값을 사용합니다.

```php
$mublo['site'];        // 도메인, 사이트·회사·SEO 설정과 이미지
$mublo['viewer'];      // 로그인 여부와 현재 회원 스냅샷
$mublo['request'];     // 현재 URL, 경로와 쿼리
$mublo['navigation'];  // 메뉴와 현재 메뉴 코드
$mublo['theme'];       // 현재 프레임 스킨
$mublo['security'];    // CSRF 토큰
```

스킨 안에서는 ViewContext 헬퍼도 사용할 수 있습니다.

```php
$this->assets->addCss('/assets/css/my-index.css');
$this->assets->addJs('/assets/js/my-index.js');
$this->component('pagination', $pagination);
```

standalone 문서에서 AssetManager를 사용한다면 `<head>` 안에 `<!-- MUBLO_CSS -->`, `</body>` 앞에 `<!-- MUBLO_JS -->` 마커를 반드시 배치해야 합니다.

## 원본 Context와 서비스 접근

직접 PHP를 개발하는 신뢰된 운영자가 원본 `Context`나 Core 서비스를 사용해야 하는 경우 DI 컨테이너에서 가져올 수 있습니다.

```php
<?php
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;

$container = DependencyContainer::getInstance();
$context = $container->get(Context::class);
```

표시 용도의 값은 가능한 한 `$mublo`를 사용하고, 조회나 비즈니스 로직이 커지면 Plugin 또는 Package 서비스로 분리하는 것을 권장합니다.

## 주의사항

- PHP 스킨은 서버의 PHP 권한으로 실행됩니다. 신뢰된 서버 운영자 또는 개발자만 배포해야 합니다.
- 출력값은 용도에 맞게 `htmlspecialchars()` 등으로 이스케이프해야 합니다.
- standalone은 Core `Head.php`와 `Foot.php`를 사용하지 않으므로 SEO 메타, favicon, 분석 스크립트, `custom_body_script` 등이 필요하면 스킨에서 직접 출력해야 합니다.
- `index` 블록은 기본 스킨이 실행될 때만 지연 렌더링됩니다. standalone과 chromeless 스킨에서는 `index` 블록 조회도 실행되지 않습니다.
- standalone과 chromeless 분기는 현재 `FrontFootRenderEvent`와 `PageViewedEvent`보다 먼저 종료됩니다. 이 이벤트에 의존하는 팝업이나 방문 통계 확장이 필요한지 확인해야 합니다.
- 여러 도메인이 한 설치본을 공유한다면 스킨의 사용자 파일과 설정도 도메인별로 분리해야 합니다.
