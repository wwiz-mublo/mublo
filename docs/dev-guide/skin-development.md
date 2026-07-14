# 스킨 제작 튜토리얼

이 문서는 처음 Mublo 스킨을 만드는 개발자가 실제 파일을 추가하고 관리자에서 선택해 보는 실습입니다. 첫 결과물로 로그인 화면 하나를 바꾸고, 같은 원칙을 프레임·블록·게시판·쇼핑몰 스킨에 적용합니다.

## 1. 스킨 종류 고르기

Mublo의 스킨은 적용 범위에 따라 나뉩니다.

| 종류 | 경로 | 용도 | 선택 위치 |
|---|---|---|---|
| Front 콘텐츠 | `views/Front/{Group}/{skin}/` | 로그인, 회원가입, 마이페이지처럼 화면 일부 교체 | 관리자 **환경설정 > 테마** |
| Front 프레임 | `views/Front/frame/{skin}/` | Head, Header, Layout, Footer 등 사이트 전체 셸 | 관리자 **환경설정 > 테마** 또는 블록 에디터 |
| Core 블록 | `views/Block/{type}/{skin}/` | 블록 한 칸의 출력 | 블록 설정의 스킨 선택 |
| Package 화면 | `packages/{Package}/views/Front/.../{skin}/` | 게시판·쇼핑몰 같은 Package 화면 | 해당 Package 설정 |
| Package/Plugin 블록 | `packages|plugins/{Name}/views/Block/.../{skin}/` | 확장이 등록한 블록 출력 | 블록 설정의 스킨 선택 |

처음에는 **Front 콘텐츠 스킨**이 좋습니다. 한 파일만 추가해도 되고, 없는 파일은 `basic`의 같은 파일로 자동 폴백됩니다.

## 2. 로그인 콘텐츠 스킨 만들기

이번 스킨 이름은 `starter`로 하겠습니다. 이름은 영문·숫자·`-`·`_`만 사용하고 64자를 넘기지 않습니다.

다음 구조를 만듭니다.

```text
views/Front/Auth/starter/
├── Login.php
└── _assets/
    └── css/
        └── login.css
```

`FindAccount.php`, `ResetPassword.php` 등을 만들지 않아도 됩니다. 해당 화면은 `views/Front/Auth/basic/`의 파일을 계속 사용합니다.

### Login.php

```php
<?php
/**
 * @var array $mublo
 * @var string $redirect
 * @var bool $useEmailAsUserId
 * @var list<string> $loginFormExtras
 */
$this->layout(['header' => false, 'footer' => false]);
$this->assets->addCss('/serve/front/view/auth/starter/css/login.css');

$siteName = $mublo['site']['config']['site_title'] ?? 'MUBLO';
$siteImages = $mublo['site']['images'];
$logo = $siteImages['logo_pc'] ?? $siteImages['logo_mobile'] ?? '';
$isEmailId = $useEmailAsUserId ?? false;
?>

<main class="starter-login">
    <section class="starter-login__card">
        <a class="starter-login__brand" href="/">
            <?php if ($logo !== ''): ?>
                <img src="<?= e($logo) ?>" alt="">
            <?php endif; ?>
            <span><?= e($siteName) ?></span>
        </a>

        <h1>로그인</h1>
        <div id="login-error" class="starter-login__error" hidden></div>

        <form id="login-form" autocomplete="off">
            <input type="hidden" name="redirect" value="<?= e($redirect ?? '/') ?>">

            <label for="user_id"><?= $isEmailId ? '이메일' : '아이디' ?></label>
            <input
                id="user_id"
                name="user_id"
                type="<?= $isEmailId ? 'email' : 'text' ?>"
                required
            >

            <label for="password">비밀번호</label>
            <input id="password" name="password" type="password" required>

            <button
                type="button"
                class="mublo-submit"
                data-target="/login"
                data-callback="starterLoginComplete"
            >로그인</button>
        </form>

        <?php foreach ($loginFormExtras ?? [] as $html): ?>
            <?= $html ?>
        <?php endforeach; ?>
    </section>
</main>

<script>
window.starterLoginComplete = function (response) {
    if (response.result === 'success') {
        location.href = (response.data && response.data.redirect) || '/';
        return;
    }

    var error = document.getElementById('login-error');
    error.hidden = false;
    error.textContent = response.message || '로그인에 실패했습니다.';
};
</script>
```

중요한 부분은 세 가지입니다.

- 공통 사이트 정보는 `$mublo`에서 읽습니다.
- 로그인 화면 전용 값인 `$redirect`, `$useEmailAsUserId`, `$loginFormExtras`는 Controller가 넘긴 이름 있는 payload를 사용합니다.
- 서비스, DB, 세션, DI 컨테이너를 스킨에서 직접 조회하지 않습니다.

### login.css

```css
.starter-login {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 24px;
    background: #f6f7fb;
}

.starter-login__card {
    width: min(100%, 420px);
    padding: 36px;
    border-radius: 24px;
    background: #fff;
    box-shadow: 0 20px 60px rgb(15 23 42 / 10%);
}

.starter-login__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 28px;
    color: inherit;
    text-decoration: none;
    font-weight: 700;
}

.starter-login__brand img { max-width: 120px; max-height: 36px; }
.starter-login label { display: block; margin: 18px 0 8px; }
.starter-login input { width: 100%; min-height: 44px; padding: 0 12px; }
.starter-login button { width: 100%; min-height: 46px; margin-top: 24px; }
.starter-login__error { margin: 16px 0; color: #b42318; }
```

## 3. 관리자에서 적용하기

1. 관리자 **환경설정 > 테마**로 이동합니다.
2. **프론트 콘텐츠 스킨 설정**에서 Auth 스킨을 `starter`로 선택합니다.
3. 저장한 뒤 로그아웃 상태에서 `/login`을 엽니다.

디렉터리가 정상적으로 만들어졌다면 선택 목록에 자동으로 나타납니다. 파일명이 틀렸거나 스킨 디렉터리가 없으면 안전하게 `basic`으로 폴백됩니다.

확인할 항목:

- CSS 요청 `/serve/front/view/auth/starter/css/login.css`가 `200`인지
- 로그인 성공과 실패 메시지가 모두 동작하는지
- 비회원 주문 같은 확장 UI가 `$loginFormExtras` 위치에 표시되는지
- `FindAccount.php`를 만들지 않았어도 계정 찾기 화면이 열리는지

## 4. `$mublo` 공통 데이터 사용하기

모든 Front 프레임·콘텐츠·블록·Plugin/Package 삽입 뷰는 같은 구조를 사용합니다.

```php
$site = $mublo['site'];
$viewer = $mublo['viewer'];
$request = $mublo['request'];
$navigation = $mublo['navigation'];
$theme = $mublo['theme'];
$security = $mublo['security'];
```

회원 데이터는 사용 가능 여부부터 확인합니다.

```php
<?php if ($mublo['viewer']['available'] && $mublo['viewer']['authenticated']): ?>
    <strong><?= e($mublo['viewer']['member']['displayName'] ?? '') ?></strong>
<?php endif; ?>
```

관리자 미리보기나 캐시 가능한 블록에서는 `viewer`, `request`, `security`가 비활성화될 수 있습니다. 전체 키와 캐시 규칙은 [Front 스킨 데이터 계약](../reference/front-view-data-contract.md)을 따릅니다.

## 5. 화면 payload 확인하기

`$mublo`에는 모든 화면에 공통인 정보만 있습니다. 게시글, 상품, 검색 결과처럼 화면마다 다른 데이터는 `$article`, `$product`, `$items` 같은 이름 있는 변수로 전달됩니다.

새 스킨을 만들 때는 다음 순서로 확인합니다.

1. 같은 화면의 `basic` 스킨 상단 docblock을 읽습니다.
2. Board는 `ArticlePresenter`, Shop은 `ProductPresenter`의 공개 필드를 확인합니다.
3. 원시값과 포맷값 중 디자인에 맞는 값을 선택합니다.
4. 키가 없을 때의 빈 상태를 처리합니다.

```php
<?php foreach ($items ?? [] as $item): ?>
    <a href="<?= e($item['url'] ?? '#') ?>">
        <?= $item['title_safe'] ?? '' ?>
    </a>
    <span><?= e($item['date_relative'] ?? '') ?></span>
<?php endforeach; ?>
```

`title_safe`처럼 Presenter가 안전한 HTML 문자열로 명시한 필드를 제외하면 사용자 입력은 `e()`로 이스케이프합니다. DB 행이나 Entity에 우연히 있던 키에는 의존하지 않습니다.

## 6. 프레임 스킨으로 확장하기

사이트 전체 셸을 바꾸려면 다음 구조를 사용합니다.

```text
views/Front/frame/starter/
├── Head.php
├── Header.php
├── LayoutOpen.php
├── LayoutClose.php
├── Footer.php
├── Foot.php
└── _assets/
    ├── css/front.css
    └── js/front.js
```

프레임은 문서 구조를 소유하므로 처음에는 `basic`의 파일을 기준으로 시작하는 편이 안전합니다. 다음 마커는 제거하지 않습니다.

```html
<!-- MUBLO_CSS_component -->
<!-- MUBLO_CSS -->
<!-- MUBLO_JS -->
```

에셋 주소는 다음과 같습니다.

```php
$this->assets->addCss('/serve/front/starter/css/front.css');
$this->assets->addJs('/serve/front/starter/js/front.js');
```

Header와 Footer에서는 `$mublo['navigation']`, `$mublo['site']`, `$mublo['viewer']`를 사용합니다. 프레임 내부에서 메뉴나 회원 서비스를 다시 조회하지 않습니다.

## 7. 블록 스킨 만들기

기존 HTML 블록에 `card` 스킨을 추가한다면 다음 경로를 만듭니다.

```text
views/Block/html/card/
├── card.php
└── style.css
```

`card.php`의 기본 형태:

```php
<?php
/** @var array $mublo */
/** @var array $titleConfig */
/** @var string $titlePartial */
/** @var string $html */
/** @var \Mublo\Core\Rendering\AssetManager|null $assets */
$assets?->addCss('/serve/block/html/card/style.css');
?>

<section class="html-card">
    <?php include $titlePartial; ?>
    <div class="html-card__body"><?= $html ?></div>
</section>
```

캐시 가능한 블록은 방문자별 데이터가 제거됩니다. 로그인 상태나 현재 요청에 따라 출력이 달라지는 새 콘텐츠 타입은 `BlockRegistry` 등록 시 `options: ['noCache' => true]`가 필요합니다. 단순히 스킨에서 회원 정보를 읽기 위해 캐시 규칙을 우회하지 않습니다.

## 8. 게시판·쇼핑몰 스킨 만들기

Package 스킨도 원칙은 같습니다.

```text
packages/Board/views/Front/Board/{skin}/
├── List.php
├── View.php
└── Write.php

packages/Shop/views/Front/Product/{skin}/
├── List.php
├── View.php
└── _assets/
```

- Board 스킨은 관리자 게시판 설정의 **게시판 스킨**에서 선택합니다.
- Shop 스킨은 관리자 Shop 설정의 기능별 콘텐츠 스킨에서 선택합니다.
- Board의 `$items`·`$article`은 `ArticlePresenter`, Shop의 `$products`·`$product`는 `ProductPresenter`가 정한 공개 데이터입니다.
- 새 디자인에 필요한 정보가 계약에 없다면 스킨에서 Repository를 호출하지 말고 Presenter 또는 Controller payload 계약에 추가합니다.

## 9. 배포 전 체크리스트

- 바꿀 파일만 추가했고 `basic` 폴백을 확인했다.
- `$mublo`와 화면 payload만 사용한다.
- 사용자 입력을 `e()`로 이스케이프한다.
- CSS·JS를 `/serve/...` 경로와 AssetManager로 등록한다.
- 빈 목록, 비로그인, 미리보기 상태를 확인했다.
- 모바일 화면과 키보드 조작을 확인했다.
- 프레임의 `MUBLO_CSS`·`MUBLO_JS` 마커를 유지했다.
- 캐시 블록에서 회원·요청 데이터에 의존하지 않는다.
- `composer check`를 통과했다.

## 관련 문서

- [Front 스킨 데이터 계약](../reference/front-view-data-contract.md)
- [테마·스킨·렌더링](../architecture/18-theme-rendering.md)
- [블록 시스템 개발](block-system.md)
- [호환성 정책](../compatibility-policy.md)

---

[< 개발자 가이드로](README.md)
