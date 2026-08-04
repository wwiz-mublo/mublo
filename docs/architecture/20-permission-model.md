# 20. 권한 모델

Mublo의 권한 모델은 "코어는 도메인 운영자까지, 기능별 세부 권한은 확장의 소관"이라는 한 문장으로 요약된다. 코어는 로그인·관리자·최고관리자·도메인 운영자라는 계층 판정과, 관리자 메뉴 단위의 접근 제어(Negative ACL)까지만 책임진다. 게시판별 읽기/쓰기 같은 기능 내부의 세부 권한은 각 Package가 스스로 판정한다.

이 장이 다루는 기능: 관리자 권한 목록·서브메뉴 권한 조회(`src/Controller/Admin/AdminPermissionsController.php` — index, submenus), 관리자 권한 부여(store), 관리자 권한 회수 개별/일괄/등급 단위(delete, bulkDelete, levelDelete), 관리자 로그인·로그아웃(`src/Controller/Admin/AuthController.php` — loginForm, login, logout), 대리 로그인 토큰 검증(proxyLoginVerify), 하위 도메인 대리 로그인 발급(`src/Controller/Admin/DomainsController.php` — proxyLogin), 관리자 인증 라우트 `/admin/login`·`/admin/logout`·`/admin/proxy-login`(`src/Core/App/Router.php`).

## 책임과 비책임

- **책임** — 인증(로그인/로그아웃/세션), 계층 판정(비회원 → 회원 등급 → 관리자 → 도메인 운영자 → 최고관리자), 관리자 메뉴 접근 제어, 로그인 시도 제한, 대리 로그인.
- **비책임** — 기능별 세부 권한. 어떤 게시판을 누가 읽을 수 있는지 코어는 알지 못한다. 그 판정은 Package의 서비스(예: `packages/Board/Service/BoardPermissionService.php`)가 소유한다.

## 권한의 계층 — AuthService의 판정 메서드

모든 권한 판정의 출발점은 `src/Service/Auth/AuthService.php`다. 세션(`auth_user` 키)에 저장된 회원 스냅샷을 읽어 다음 메서드로 계층을 판정한다.

| 메서드 | 판정 | 계층 |
|---|---|---|
| `check()` / `guest()` | 로그인 여부 | 회원 / 비회원 |
| `hasLevel(int $requiredLevel)` | `level_value >= $requiredLevel` | 회원 등급 |
| `isAdmin()` | `is_admin` 또는 `is_super` | 관리자 |
| `canOperateDomain()` | `is_super` 또는 `can_operate_domain` | 도메인 운영자 |
| `isSuper()` | `is_super` | 최고관리자 |

`is_super`·`is_admin`·`can_operate_domain`은 개별 회원이 아니라 **회원 등급**(`member_levels` 테이블, `src/Entity/Member/MemberLevel.php`)의 속성이다. 설치 시 기본 등급 6종이 시드된다(`src/Core/Install/Installer.php`의 `seedDefaultLevels`).

```text
255 SUPER    최고관리자  is_super=1, is_admin=1, can_operate_domain=1
230 STAFF    스태프                 is_admin=1
220 PARTNER  파트너                 is_admin=1
215 SITE_MASTER 사이트 운영자        is_admin=1, can_operate_domain=1
210 SUPPLIER 공급처     (플래그 없음)
  1 BASIC    일반회원   (플래그 없음)
```

즉 "도메인 운영자"는 별도 계정 유형이 아니라 `can_operate_domain=1` 등급을 가진 관리자다. 참고로 블록의 직접 입력 JS/CSS 는 이 게이트조차 걸지 않는다 — 블록 편집 접근권 자체가 신뢰 부여이므로 편집자 전원에게 허용되고(편집 자율성 정책, `BlockColumnWriteContextFactory`), 서버사이드 실행인 Include 만 `isSuper()` 게이트다. 회원 등급 체계 자체는 [19. 회원·커스텀 필드](19-member-custom-fields.md)에서 다룬다.

### 세션 스냅샷과 재검증

AuthService가 사용하는 세션 키는 네 개다(클래스 상수).

| 세션 키 | 내용 |
|---|---|
| `auth_user` | 회원 스냅샷 (`Member::toSafeArray()` + 아바타 URL 캐시) |
| `auth_login_time` | 로그인 시각 |
| `proxy_login` | 대리 로그인 정보 (발급 관리자·출발 도메인·사이트명) |
| `auth_revalidated_at` | 마지막 권한 재검증 시각 (스로틀용) |

로그인 성공 시 `loginUser()`가 하는 일은 네 가지다.

1. `session->regenerate(true)` — 세션 ID 재생성(세션 고정 공격 방지). 로그아웃 시에도 재생성한다.
2. `Member::toSafeArray()` — 민감 정보를 제거한 배열만 세션 `auth_user` 키에 저장한다.
3. 아바타 URL을 세션에 캐시한다(매 요청 N+1 조회 회피, `resolveAvatarUrl`).
4. `auth_login_time`에 로그인 시각을 기록한다.

세션 스냅샷은 로그인 시점 값이므로, 이후 강등·차단·탈퇴가 반영되지 않는 공백이 생긴다. `revalidatePrivileges(int $ttl = 60)`가 이 공백을 메운다 — 최대 60초에 한 번(관리자 트래픽 부하 억제 스로틀) DB를 재조회해 세션 권한 스냅샷을 갱신하고, 계정이 더 이상 존재하지 않거나 비활성이면 `false`를 반환해 호출자(AdminMiddleware)가 로그아웃시키게 한다. 등급·도메인 변경 직후의 명시적 동기화에는 `refreshSession()`을 쓴다.

SNS 로그인 등 외부 인증은 비밀번호 검증 없이 `loginByMember(Member $member)`로 같은 세션 절차를 태운다. 로그인 성공 시에는 공식 Event `MemberLoggedInEvent`(`src/Service/Auth/Event/`)가 발행된다 — 가입·로그인 후속 처리를 확장이 구독하는 지점이다([08. Event](08-event.md)).

## 관리자 권한 관리 (Admin-permissions)

최고관리자가 관리자 등급별로 관리자 메뉴의 접근을 제한하는 화면이다. 컨트롤러는 `src/Controller/Admin/AdminPermissionsController.php`, 화면은 `views/Admin/Admin-permissions/Index.php`(좌측 제한 목록 + 우측 설정 폼의 2컬럼), 판정·저장 로직은 `src/Service/Admin/AdminPermissionService.php`다.

컨트롤러의 모든 액션은 서두에서 `AuthService::isSuper()`를 확인하고, 아니면 403을 반환한다. 또한 최고관리자 등급 자체는 제한 대상으로 저장할 수 없다(`saveFromForm`이 `level->isSuper()`를 거부).

### Negative ACL

방식은 블랙리스트다 — 기본적으로 모든 관리자(`is_admin=1`)는 모든 메뉴에 접근 가능하고, `member_level_denied_menus` 테이블에 등록된 (도메인, 등급, 메뉴 코드, 차단 액션)만 차단된다. 액션은 6종(`list, read, write, edit, delete, download` — `AdminPermissionService::ALLOWED_ACTIONS`)이며, UI에서는 5개 그룹으로 묶어 체크한다(`ACTION_GROUPS`).

```text
l 접근(list)   r 읽기(read)   w 쓰기(write+edit)   d 삭제(delete)   f 다운로드(download)
```

저장 폼은 `formData[level_value]` + `formData[menu_code]`(1차 메뉴) + `formData[submenu][{코드}][]`(서브메뉴별 차단 그룹) 구조이고, 체크를 모두 해제하면 해당 행이 삭제된다. 회수는 세 단위를 지원한다 — 개별(`delete/{id}`), 선택 일괄(`bulk-delete`), 등급 전체(`level-delete/{levelValue}`).

Plugin/Package가 등록한 관리자 메뉴도 대상이다. 코드가 없는 확장 루트 메뉴는 `plugin:MemberPoint`, `package:Shop` 형식의 식별자로 다루고(`findSubmenus`), 저장된 메뉴 코드는 `P_MemberPoint_001`, `K_Shop_001` 형식이다(`extractParentCode` 주석 기준).

### 판정 규칙 — isDenied

요청 시 차단 판정은 `AdminPermissionService::isDenied(domainId, levelValue, menuCode, action, domainGroup)`이 한다. 세 가지 규칙이 합산된다.

1. **상위 메뉴 상속** — `003_001`이 명시 차단이 아니어도 상위 `003`이 차단이면 차단(`extractParentCode`).
2. **상위 도메인 상속** — `domain_group`(예: `1/2/5`) 체인의 어느 도메인에서든 차단이면 차단 확정(합집합, `getDomainChain`). 상위 도메인 운영자가 하위 도메인 관리자의 권한을 일괄 제한할 수 있다.
3. **액션 판정** — `denied_actions`가 `*`면 전체 차단, 아니면 쉼표 목록에 현재 액션이 포함될 때 차단.

현재 요청의 액션은 URL에서 추론한다(`detectAction`). URL 세그먼트가 액션 키워드와 일치하거나 `-{keyword}`로 끝나면 매핑되고(`store→write`, `status-edit→edit`, `export→download` 등), 마지막 세그먼트가 숫자면 `read`, 그 외는 `list`다. **따라서 관리자 라우트의 URL에는 액션 키워드를 일관되게 포함해야 한다** — 이 명명 규칙을 어기면 쓰기 엔드포인트가 `list`로 분류되어 차단을 우회한다.

미들웨어 밖에서 회원 객체 기준으로 같은 판정이 필요하면 `canAccess(Member $member, ...)`를 쓴다 — 최고관리자 통과, 비관리자 거부, 나머지는 `isDenied` 위임 순서다.

## 미들웨어 계층

권한 판정을 요청 파이프라인에 강제하는 것은 `src/Core/Middleware/`의 미들웨어다. 라우트별 지정은 라우트 정의의 `middleware` 배열로 한다([05. Router](05-router.md)).

```php
// src/Core/App/Router.php 발췌
$r->addRoute('GET', '/admin', [
    'controller' => \Mublo\Controller\Admin\DashboardController::class,
    'method'     => 'index',
    'middleware' => [\Mublo\Core\Middleware\AdminMiddleware::class],
]);
```

### AuthMiddleware — 인증만

`src/Core/Middleware/AuthMiddleware.php`. 비로그인 시 AJAX면 401 JSON, 아니면 로그인 페이지(`/login` 또는 관리자 영역이면 `/admin/login`)로 원래 URL을 `?redirect=`에 보존해 리다이렉트한다. 권한 판정은 하지 않는다 — 프론트의 로그인 필수 라우트에 명시적으로 붙인다.

### AdminMiddleware — 관리자 영역 표준 게이트

`src/Core/Middleware/AdminMiddleware.php`. 처리 순서는 다섯 단계다.

1. 로그인 확인 — 미로그인 시 `/admin/login` 리다이렉트.
2. `isAdmin()` 확인 — 비관리자는 거부(로깅 후 리다이렉트).
3. `revalidatePrivileges()` — DB 재검증. 실패하거나 재검증 후에도 관리자가 아니면(강등 반영) 강제 로그아웃 + `session_expired` 리다이렉트.
4. `isSuper()`면 전부 통과.
5. 그 외 관리자는 `AdminMenuService::getActiveCode(path)` + `detectAction(path)`으로 `isDenied` 판정. 차단이면 403(AJAX는 JSON), 거부 사유는 `logAccessDenied`로 로깅.

### super_only — 미들웨어가 아니라 라우트 등록 억제

manifest 의 `super_only: true` 는 미들웨어로 막지 않는다. `Router::isSuperOnlyPluginOnSubSite()` 가 하위 도메인에서 **해당 플러그인의 라우트 자체를 등록하지 않는다**([09. 멀티 도메인](09-multi-domain.md)).

막는 시점이 요청 처리가 아니라 라우트 구성이라, 하위 도메인에서는 그 경로가 존재하지 않는 것과 같다 — 403 이 아니라 404 다.

> 예전에는 `SuperOnlyMiddleware` 가 같은 목적의 보조 가드로 있었으나 제거됐다. 이 문서를 보고 그 클래스를 찾을 필요는 없다.

### autoResolve와 미들웨어

Admin 영역의 autoResolve(명시 라우트가 없을 때 URL → Controller/Method 자동 매핑, `src/Core/App/Router.php`의 `autoResolve`)는 **AdminMiddleware를 자동 적용**한다. Front 영역은 autoResolve 자체가 허용되지 않는다 — autoResolve는 라우트별 미들웨어를 부여할 수 없기 때문이다(Front 엔드포인트는 전부 명시 라우트).

다만 autoResolve 가 붙여 주는 것은 AdminMiddleware 까지다. 자동 매핑되는 관리자 컨트롤러에서 최고관리자·도메인 운영자 제한이 필요하면 **메서드 안에서 직접 확인**해야 한다(아래 확장 규약). `/admin/admin-permissions` 화면이 실례다 — autoResolve 로 매핑되고, 컨트롤러가 `isSuper()` 를 직접 확인한다.

autoResolve 는 선언된 이름과 대소문자까지 같을 때만 통과시킨다. PHP 는 클래스·메서드 이름의 대소문자를 가리지 않아 `/admin/MEMBER/delete` 도 해석되는데, 위의 권한 판정은 요청 경로를 메뉴 URL 과 그대로 비교하므로 그런 경로는 어떤 메뉴에도 매칭되지 않는다. 빈 메뉴코드는 `isDenied()` 가 허용으로 처리하므로, 판정을 일치시키지 않으면 대소문자만 바꿔 차단된 메뉴에 닿을 수 있다.

예외적으로 `/admin/login`(GET/POST)·`/admin/logout`·`/admin/proxy-login`은 미들웨어 없이 등록된다 — 비로그인 상태에서 접근해야 하는 라우트다. 대신 `AuthController::login`은 `AuthService::attempt` 성공 후 `isAdmin()`이 아니면 즉시 `logout()`시키고 "관리자 권한이 없습니다"를 반환한다 — 일반 회원 세션으로 관리자 영역 입구를 통과하지 못하게 하는 후단 방어다.

### 동작 흐름 — 관리자 요청 한 건의 판정 경로

```text
GET /admin/admin-permissions (명시 라우트 없음)
  → Router::autoResolve
      admin-permissions → AdminPermissionsController@index
      middleware = [AdminMiddleware]           ← Admin 영역 자동 부여
  → AdminMiddleware
      로그인? → isAdmin()? → revalidatePrivileges() → isSuper()?
      (isSuper=false면) getActiveCode + detectAction → isDenied?
  → AdminPermissionsController::index
      isSuper() 직접 확인                       ← 컨트롤러 자체 게이트
```

미들웨어 통과가 곧 전권이 아니라는 점이 요점이다 — 최고관리자·도메인 운영자 한정은 마지막 단계에서 컨트롤러가 스스로 건다.

## 로그인 보안

### 로그인 시도 제한 — LoginAttemptService

로그인은 `AuthService::attempt(domainId, userId, password, ipAddress)`가 처리하며, `src/Service/Auth/LoginAttemptService.php`가 시도를 제한한다.

- **계정 기준** 15분(`decay_seconds=900`) 내 실패 5회(`max_attempts_per_user`), **IP 기준** 실패 20회(`max_attempts_per_ip`)를 넘으면 차단하고 남은 대기 시간을 안내한다. 성공하면 해당 계정의 실패 기록이 초기화된다. 기록은 `login_attempts` 테이블에 남고 24시간 지난 행은 확률적으로(5%) 청소된다.
- DB 장애 등으로 확인이 불가능하면 **fail-open**이다 — 로그인을 막지 않되 `[SECURITY]` 접두사로 반드시 로깅한다. 가용성을 우선하되, 브루트포스 보호가 조용히 꺼진 상태를 남기지 않기 위함이다.
- `attempt` 자체에도 열거 방어가 있다. 존재하지 않는 계정에는 더미 bcrypt 해시로 검증 시간을 평준화하고(타이밍 기반 계정 열거 완화), 계정 상태(휴면·정지 등) 안내는 비밀번호가 맞은 뒤에만 노출한다 — 자격증명 없이 계정 상태를 열거하지 못하게 하는 순서다.

비인증 공개 엔드포인트 일반의 남용 방지는 별도 계층인 `RateLimiter`가 담당한다([10. 인프라 서비스](10-infrastructure.md)).

### 대리 로그인 — ProxyLoginService

상위 도메인 관리자가 하위 도메인의 관리자 패널에 들어가는 공식 경로다(`src/Service/Auth/ProxyLoginService.php`).

```text
상위 도메인                                         하위 도메인
POST /admin/domains/proxy-login/{id}
  ├─ verifyDomainHierarchy (domain_group 하위 검증)
  ├─ 일회용 토큰 발급 (TTL 30초, proxy_login_tokens)
  └─ 응답: //{하위도메인}/admin/proxy-login?token=…
                     │ 브라우저 이동
                     ▼
                              GET /admin/proxy-login?token=…
                                ├─ verifyToken (미사용·미만료·도메인 일치)
                                ├─ 도메인 소유자로 loginByMember
                                └─ 세션 proxy_login + 헤더 배지 표시
```

1. **발급** — `POST /admin/domains/proxy-login/{id}`(`DomainsController::proxyLogin`). 발급 전에 `verifyDomainHierarchy`가 대상이 현재 도메인의 `domain_group` 하위인지 검증한다. 자기 자신은 거부하고, 그룹 정보가 비어 있으면 fail-closed로 거부한다(과거 임의 도메인 대리 로그인이 가능하던 권한 상승 취약점의 수정 — 주석 원문). 통과하면 **TTL 30초·일회용 토큰**을 `proxy_login_tokens`에 저장하고 대상 도메인의 `/admin/proxy-login?token=...` URL을 반환한다.
2. **검증** — 대상 도메인의 `GET /admin/proxy-login`(`AuthController::proxyLoginVerify`, 미들웨어 없음 — 토큰이 곧 인증)이 `verifyToken`을 호출한다. 미사용·미만료·대상 도메인 일치를 확인한 뒤 토큰을 사용 처리하고, **대상 도메인 소유자**(`domain_configs.member_id`)로 로그인시킨다(`loginByMember`). 상위 관리자의 계정이 아니라 소유자 계정으로 들어가는 것이다.
3. **표시** — `setProxyLogin`이 발급 관리자 정보(출발 도메인, 관리자 ID·닉네임, 사이트명)를 세션 `proxy_login` 키에 저장하고, 관리자 헤더(`views/Admin/frame/basic/Header.php`, 데이터 주입은 `src/Core/Rendering/AdminViewRenderer.php`)가 "○○님이 △△에 대리접속 중" 배지를 상시 노출한다. `AuthService::isProxyLogin()`/`getProxyLogin()`으로 코드에서도 판별할 수 있다.

감사 흔적은 여기까지다 — 세션 배지와 `proxy_login_tokens` 행이 전부이며, 사용된 토큰 행은 다음 발급 시점의 정리(`cleanExpiredTokens`)에서 삭제된다. 대리 로그인 이력을 남기는 영구 감사 로그는 현재 지원하지 않는다.

## 코어 권한 vs Package 권한

코어의 권한 어휘는 "도메인 운영자까지"다. 기능 내부의 세부 권한은 각 Package가 자기 데이터로 판정한다.

레퍼런스 사례가 Board의 `packages/Board/Service/BoardPermissionService.php`다 — `canList`/`canRead`/`canWrite`/`canComment`/`canDownload`/`canModify`/`canDelete` 등을 게시글 → 카테고리 매핑 → 게시판 → 그룹 우선순위의 자체 설정과 `board_permissions` 테이블로 판정하고, 코어에는 `AuthService`의 계층 판정(로그인 여부, 회원 등급)만 의존한다. 코어는 이 판정에 개입하지 않고, 게시판이라는 개념 자체를 모른다. 상세 해설은 [33. Reference Packages](33-reference-packages.md).

경계가 겹치는 지점이 관리자 메뉴다. Package가 등록한 관리자 화면은 코어의 AdminMiddleware + Admin-permissions Negative ACL의 적용을 받는다(메뉴 코드 `K_Shop_001` 형식). 즉 "관리자 메뉴 접근 가능 여부"는 코어가, "그 기능 안에서 무엇이 가능한가"는 Package가 담당한다.

Report 엔진의 다운로드 게이트도 같은 구도다 — `src/Core/Report/Security/AdminPermissionGate.php`는 관리자 인증과 최고관리자 통과를 확인한 뒤 `AdminPermissionService::isDenied(..., 'download')`로 위임한다. 상세는 [23. Report 엔진](23-report-engine.md)에서 다룬다.

## 확장 개발자 규약

확장이 지켜야 할 게이트 지점은 세 가지다.

**1) 관리자 화면·엔드포인트.** autoResolve가 AdminMiddleware까지는 보장하지만, 그 이상(최고관리자·도메인 운영자 한정)은 컨트롤러가 직접 확인해야 한다. 코어의 표준 패턴은 `denyUnlessDomainOperator`다(`src/Controller/Admin/BlockKitControllerTrait.php`).

```php
private function denyUnlessDomainOperator(string $subject = '블록 킷 기능은'): ?JsonResponse
{
    $authService = $this->container->get(AuthService::class);
    if (!$authService->canOperateDomain()) {
        return JsonResponse::forbidden("{$subject} 도메인 운영자 이상만 사용할 수 있습니다.");
    }
    return null;
}
```

`BlockEditorController`·`BlockKitController`·`BlockRowController`·`BlockPageController`(모두 `src/Controller/Admin/`)가 임의 HTML/JS를 다루는 모든 액션 서두에서 이 가드를 호출한다. 확장의 관리자 컨트롤러도 위험 액션(임의 마크업 저장, 파일 반입 등)에는 같은 방식으로 `canOperateDomain()` 또는 `isSuper()`를 걸어야 한다.

**2) 관리자 대시보드 위젯.** `DashboardWidgetInterface::canView(Context $context): bool`(`src/Core/Dashboard/DashboardWidgetInterface.php`)이 위젯 단위 노출 권한 훅이다. 민감한 수치를 담는 위젯은 여기서 계층을 판정한다. 위젯 등록은 [22. 관리자 대시보드 위젯](22-admin-dashboard-widgets.md) 참조.

**3) 보안 파일 다운로드.** 코어가 모르는 category의 파일 접근 권한은 `SecureFileAccessEvent`(`src/Core/Event/Storage/SecureFileAccessEvent.php`) 구독자가 `grant()`로 허용한다. 아무 구독자도 허용하지 않으면 관리자만 통과하는 fail-closed다. 흐름 전체는 [10. 인프라 서비스](10-infrastructure.md)의 SecureFileService 절 참조.

**Best Practice** — 관리자 라우트 URL에 액션 키워드(`store`, `edit`, `delete`, `download` 등)를 일관되게 포함해 `detectAction`이 올바르게 분류하게 한다. 위험 액션은 응답 분기(뷰 숨김)가 아니라 서버 측 판정 메서드로 막는다.

**Anti Pattern** — 프론트 응답에서 `is_admin` 같은 세션 값을 뷰 분기에만 쓰고 서버 판정을 생략하는 것, 관리자 쓰기 엔드포인트 URL에 액션 키워드를 넣지 않아 `list`로 오판되게 만드는 것, 최고관리자 전용 화면을 AdminMiddleware만 믿고 컨트롤러 게이트(`isSuper()`/`canOperateDomain()`) 없이 노출하는 것.

## 관련 문서

- [05. Router](05-router.md) — 라우트별 미들웨어 지정, autoResolve의 Admin 한정 동작
- [09. 멀티 도메인](09-multi-domain.md) — 도메인 계층(domain_group), super_only Plugin의 라우트 억제
- [10. 인프라 서비스](10-infrastructure.md) — Session·CSRF·RateLimiter·SecureFileService 등 보안 기반
- [08. Event](08-event.md) — `MemberLoggedInEvent`·`SecureFileAccessEvent` 구독 방법
- [33. Reference Packages](33-reference-packages.md) — Board의 권한 판정 실전 구조
