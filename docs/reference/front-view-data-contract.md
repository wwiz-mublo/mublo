# Front 스킨 데이터 계약

모든 Front 프레임·콘텐츠·블록·Plugin/Package 삽입 뷰에는 예약 변수 `$mublo`가 전달됩니다. 스킨은 Core 서비스나 세션, DB를 직접 조회하지 않고 이 계약과 화면 고유 payload만으로 출력해야 합니다.

```php
<?php
$site = $mublo['site'];
$viewer = $mublo['viewer'];
?>

<h1><?= e($site['config']['site_title'] ?? '') ?></h1>
<?php if ($viewer['authenticated']): ?>
    <p><?= e($viewer['member']['displayName'] ?? '') ?>님</p>
<?php endif; ?>
```

## 공통 구조

현재 `contractVersion`은 `1`입니다. 아래 최상위 섹션은 일반 요청과 관리자 미리보기 모두에서 항상 존재합니다.

```php
$mublo = [
    'contractVersion' => 1,
    'site' => [
        'domainId' => 1,
        'url' => 'https://example.com',
        'config' => [],
        'company' => [],
        'seo' => [],
        'images' => [],
        'customerService' => [],
    ],
    'viewer' => [
        'available' => true,
        'authenticated' => false,
        'member' => null,
        'notificationUnreadCount' => 0,
    ],
    'request' => [
        'available' => true,
        'url' => 'https://example.com/current',
        'path' => '/current',
        'query' => [],
    ],
    'navigation' => [
        'menuTree' => [],
        'utilityMenus' => [],
        'footerMenus' => [],
        'currentMenuCode' => null,
    ],
    'theme' => ['frameSkin' => 'basic'],
    'security' => ['available' => true, 'csrfToken' => ''],
    'runtime' => [
        'area' => 'front',
        'viewerAware' => true,
        'preview' => false,
    ],
];
```

`viewer.member`가 존재할 때 공개되는 키는 다음으로 제한됩니다.

```php
[
    'memberId' => 10,
    'domainId' => 1,
    'userId' => 'hong',
    'nickname' => '홍길동',
    'displayName' => '홍길동',
    'levelValue' => 5,
    'isAdmin' => false,
    'isSuper' => false,
    'canOperateDomain' => false,
    'avatarUrl' => null,
]
```

비밀번호, 세션 원본, IP, 내부 Entity는 전달되지 않습니다.

`viewer.member.memberId`와 `viewer.member.userId`는 **현재 뷰어 본인의 상태 비교와 계정 화면용
호환 필드**다. 타인을 가리키는 URL·HTML·`data-*`·JSON에 복사하거나 그대로 출력하지 않는다.
타인 대상 컴포넌트에는 `public_id`를 전달하고, 남에게 보일 이름은 `publicDisplayName()` 정책을
적용한 값을 사용한다([회원 식별자 경계 정책](member-identifier-policy.md)).

## 화면 고유 payload

`$mublo`는 모든 화면에 공통인 정보만 담당합니다. 게시글의 `$article`, 상품의 `$product`, 목록의 `$items`처럼 화면 고유 데이터는 기존처럼 이름 있는 최상위 변수로 전달됩니다.

- Controller는 DB 행이나 Entity 전체를 그대로 View에 넘기지 않습니다.
- 고정된 공개 필드는 Presenter/DTO의 허용 목록으로 만듭니다.
- 스킨 표시 선택지를 위해 원시 표시값과 포맷값을 함께 제공합니다. 예: `view_count`와 `view_count_formatted`, `display_price`와 `display_price_formatted`.
- 새 내부 DB 컬럼은 Presenter 허용 목록에 명시적으로 추가하기 전에는 View 계약에 들어오지 않습니다.

Board의 게시글 계약은 `ArticlePresenter`, Shop의 상품 계약은 `ProductPresenter`가 소유합니다. 각 Presenter의 클래스 문서에 파생 키와 용도가 정리되어 있습니다.

## 블록과 확장 뷰

`SkinRendererTrait` 기반 블록에는 `$mublo`와 함께 `$column`, `$titleConfig`, `$titlePartial`, `$contentConfig`, `$skinDir` 및 렌더러가 명시적으로 전달한 데이터가 제공됩니다. Popup·Widget·로그인 폼 삽입 뷰도 동일한 `ViewContext`와 `$mublo`를 사용합니다.

캐시 가능한 블록은 방문자별 HTML이 섞이지 않도록 다음 값이 비활성화됩니다.

```php
$mublo['viewer']['available'] === false;
$mublo['request']['available'] === false;
$mublo['security']['available'] === false;
$mublo['security']['csrfToken'] === '';
$mublo['runtime']['viewerAware'] === false;
```

회원이나 현재 요청에 따라 달라지는 블록 타입은 `BlockRegistry`에 `options: ['noCache' => true]`로 등록해야 합니다. `outlogin`과 개발자용 `include`가 이에 해당합니다.

## 미리보기와 안전한 접근

관리자 미리보기에서는 실제 요청·회원 정보가 없지만 모든 섹션과 키는 유지됩니다. 실제 정보 사용 가능 여부는 `available`로 판단하십시오.

```php
<?php if ($mublo['viewer']['available'] && $mublo['viewer']['authenticated']): ?>
    ...
<?php endif; ?>
```

`$mublo`는 예약 키이므로 Controller, Plugin, Package가 같은 이름의 데이터를 덮어쓰면 예외가 발생합니다. 공통 계약 확장이 필요하면 새 버전의 정식 계약으로 추가하고, 확장 전용 계산 기능은 `ViewContextCreatedEvent`로 ViewHelper를 등록합니다.

## 호환성

- 같은 major 버전에서 기존 키의 의미나 타입을 바꾸지 않습니다.
- 선택 키를 추가하는 변경은 허용합니다.
- 제거·이름 변경·타입 변경은 `contractVersion` 및 major 버전 변경 대상으로 봅니다.
- 스킨은 알 수 없는 추가 키를 무시해야 합니다.

실제 계약 정의는 `src/Core/Rendering/FrontViewContract.php`, 요청 데이터 수집은 `FrontViewRenderer::collectCommonData()`, 조각 렌더링은 `FrontViewRuntime`이 담당합니다.

이 계약을 사용해 실제 스킨을 처음 만드는 과정은 [스킨 제작 튜토리얼](../dev-guide/skin-development.md)을 참고하십시오.
