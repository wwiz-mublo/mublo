# 18. 테마·스킨·렌더링

이 장은 Controller가 `ViewResponse`를 반환한 **이후**의 세계를 다룬다. Response 계층 자체([07. Response](07-response.md))와 렌더러 결정 이벤트의 발행 위치는 07장에서 이미 서술했으므로, 여기서는 Renderer 계층부터 시작한다 — 스킨이 어떻게 결정되고, 페이지가 어떤 순서로 조립되며, 확장이 어디에 개입할 수 있는가.

이 장이 다루는 기능: 프론트 프레임 스킨(`views/Front/frame`), 관리자 프레임 스킨(`views/Admin/frame`), 프레임 스킨 목록 조회·전환(`src/Controller/Admin/BlockEditorController.php`의 `frameSkins`/`frameSkin`), 스킨 에셋 서빙(`GET /serve/admin|front|package|plugin/...` → `src/Controller/Api/ServeController.php`), 렌더링 이벤트 5종(`src/Core/Event/Rendering/` — RendererResolveEvent, ViewContextCreatedEvent, SiteContextReadyEvent, FrontFootRenderEvent, PageTypeResolveEvent), 라이트/다크 테마 토글(`public/assets/js/theme.js`).

## 책임과 비책임

렌더링 계층의 등장인물은 넷이고, 책임 경계는 소스 주석에 명문화돼 있다.

| 구성 요소 | 소스 | 책임 | 하지 않는 것 |
|---|---|---|---|
| `FrontViewRenderer` | `src/Core/Rendering/FrontViewRenderer.php` | Front 페이지 전체 조립, 출력 순서 결정 | 비즈니스 로직, Admin 규칙 침범 |
| `AdminViewRenderer` | `src/Core/Rendering/AdminViewRenderer.php` | Admin 페이지 전체 조립 | Front 규칙 재사용 |
| `LayoutManager` | `src/Core/Rendering/LayoutManager.php` | body 레이아웃 타입·사이드바 데이터 결정 | 페이지 조립, Header/Footer 렌더 |
| `ViewContext` | `src/Core/Rendering/ViewContext.php` | 스킨 파일의 `$this` 컨텍스트 제공 | 출력 흐름 판단 |

두 렌더러는 `ViewRendererInterface`(`src/Core/Rendering/ViewRendererInterface.php`)를 구현하며, `render(ViewResponse $response, Context $context): void` 하나가 계약의 전부다. 조립의 주도권은 항상 렌더러에 있고 `LayoutManager`는 도구다. 이 외에 프레임 밖 에러 화면(도메인 미등록·차단·만료 등)은 `src/Core/Rendering/ErrorRenderer.php`가 독립적으로 담당한다.

## 스킨 결정 — Domain theme_config

스킨은 요청 초기, `src/Core/Context/ContextBuilder.php`의 `build()`가 Domain의 `theme_config`를 읽어 Context에 심는다(요청 Context 자체는 [04. Context](04-context.md) 참조).

- **API 요청** — 스킨 개념 없음. 즉시 반환.
- **Admin 요청** — `theme_config['admin']` → `Context::setAdminSkin()`. Admin은 Front/Frame/Block 스킨을 사용하지 않는다.
- **Front 요청** — 세 층의 스킨을 채운다.
  - **프레임 스킨**: `theme_config['frame']` → `setFrameSkin()` (기본 `basic`). Head·Header·Layout·Footer·Foot 통합 셸.
  - **콘텐츠 스킨**: 그룹별 — `member`, `auth`, `mypage`, `index`, `policy`, `search` 키 → `setFrontSkin($group, $skin)`.
  - **블록 스킨**: `setBlockSkin()` — 상세는 [17. 블록 시스템](17-block-system.md).

프레임 스킨의 관리자 전환 UI는 블록 에디터에 있다: `GET /admin/block-editor/frame-skins`(목록+현재값), `POST /admin/block-editor/frame-skin`(변경) — `src/Controller/Admin/BlockEditorController.php`.

## Front 렌더 파이프라인

`Application::handleResponse()`가 `RendererResolveEvent`로 렌더러를 결정한 뒤(07장 서술) `FrontViewRenderer::render()`가 호출되면, 다음 순서로 진행된다.

**1) 스킨·ViewContext 준비.** `Context::getFrameSkin()`/`getFrameBasePath()`를 읽고, `ViewContext('front')`를 생성해 기본 Helper를 주입한다 — `format`(`src/Helper/View/ViewFormatHelper.php`), `content`(`src/Helper/View/ViewContentHelper.php`), `assets`(`src/Core/Rendering/AssetManager.php`). 직후 `ViewContextCreatedEvent`를 발행해 확장이 자기 Helper를 등록할 기회를 준다.

**2) 공통 데이터 수집.** `collectCommonData()`는 모든 Front View에 예약 변수 `$mublo` 하나를 주입한다. `site`, `viewer`, `request`, `navigation`, `theme`, `security`, `runtime` 섹션과 `contractVersion`으로 구성되며 Controller/확장이 덮어쓸 수 없다. 프레임·콘텐츠·블록·Popup/Widget 같은 확장 조각은 요청 동안 동일한 `FrontViewRuntime`과 `ViewContext`를 사용한다. 전체 키와 캐시 규칙은 [Front 스킨 데이터 계약](../reference/front-view-data-contract.md)이 기준이다.

**3) 2-pass 조립.** Content를 먼저 버퍼에 렌더한 뒤(1차), 스킨이 선언한 레이아웃 힌트를 반영해 전체를 조립한다(2차). 스킨은 파일 상단에서 `$this->layout(['header' => false, 'footer' => false])`로 Header/Footer 생략을 선언할 수 있고, `standalone` 옵션이면 스킨이 `<html>`부터 통째로 작성한 것으로 간주해 프레임 골격 전체를 생략한다.

사이드바 레이아웃도 힌트를 따른다. `'layout' => 'full'|'left'|'right'|'both'`로 스킨이 직접 선언하면 그것이 최우선이고, 명시가 없는데 `header => false`를 선언한 화면(로그인·가입 등)은 **사이드바 없는 full로 강제**된다 — 사이드바는 헤더·푸터와 같은 사이트 크롬이라, 크롬을 벗은 페이지에 사이트 설정의 사이드바만 남지 않게 하기 위해서다. 블록 페이지는 `_pageConfig`에 `layout_type`을 담아 오므로 이 규칙의 영향을 받지 않는다.

2차 조립 순서(소스의 번호 주석 그대로):

```text
Head.php → Header.php → [subhead 블록] → LayoutOpen.php
  → [left 사이드바 블록] → contenthead 블록 + Content + contentfoot 블록 → [right 사이드바 블록]
→ LayoutClose.php → [subfoot 블록] → Footer.php → FrontFootRenderEvent HTML → Foot.php
```

사이드바 유무는 `LayoutManager::resolve()`가 결정한다. 레이아웃 타입은 `full`(1)/`left-sidebar`(2)/`right-sidebar`(3)/`both-sidebar`(4)이고, 페이지별 설정(`_pageConfig`, BlockPage 등이 전달)이 사이트 설정(`siteConfig['layout_type']`)보다 우선한다. 사이드바 너비·모바일 노출도 같은 우선순위를 따른다.

조립 완료 후 `PageViewedEvent`를 발행하며, 이때 페이지 유형 판별을 `PageTypeResolveEvent`(`src/Core/Event/Rendering/PageTypeResolveEvent.php`)로 확장에 위임한다 — 코어가 모르는 뷰 경로(`shop/...` 등)의 유형을 Package가 답할 수 있다. `PageViewedEvent`는 현재 번들 구독자가 없는 렌더 완료 확장점이고, VisitorStats의 실제 수집은 라우팅 전 `SiteContextReadyEvent`에서 일어난다([26. 통계·트래킹](26-tracking.md)).

### 콘텐츠 스킨 해석과 per-file 폴백

상대 경로 뷰(`board/list` 형식)는 `views/Front/{Group}/{skin}/{File}.php`로 해석되고, 스킨은 `Context::getFrontSkin($group) ?? 'basic'`이다. 선택 스킨에 해당 파일이 없으면 **basic 스킨의 동일 파일로 폴백**한다(`FrontViewRenderer::renderContent()`). 커스텀 스킨이 `Login.php` 한 장만 오버라이드해도 나머지는 basic으로 렌더되고, 코어가 그룹에 새 파일을 추가해도 기존 커스텀 스킨이 깨지지 않는다. 절대 경로 뷰(Package/Plugin 소유)는 `..` 포함 여부만 검사한 뒤 그대로 include한다.

### 스킨 격리 — 스킨이 죽어도 레이아웃은 산다

콘텐츠·프레임 스킨의 include는 모두 `renderViewIsolated()`를 거친다. 스킨 출력을 자체 버퍼에 모으고, 예외가 나면 그 버퍼를 통째로 버린 뒤 해당 자리에만 에러 박스(`skinErrorBox()`)를 출력한다 — 절반 출력된 열린 태그가 페이지 전체를 깨뜨리는 것을 막는 설계로, 블록 렌더의 칸 단위 격리와 같은 철학이다. 운영 모드에서는 일반 안내만, 디버그 모드에서는 예외 위치까지 표시한다.

### 에셋 파이프라인

렌더 전체는 출력 버퍼로 감싸이고, 종료 시 `flushWithAssets()`가 `AssetManager`에 등록된 CSS/JS를 플레이스홀더 치환으로 주입한다. 스킨·블록·컴포넌트는 렌더 도중 `$this->assets->addCss($path, $slot)` / `addJs($path, $slot)`를 호출하고, 프레임 스킨이 `<!-- MUBLO_CSS -->`(기본 대역, `views/Front/frame/basic/Head.php`), `<!-- MUBLO_CSS_{slot} -->`(명명 슬롯), `<!-- MUBLO_JS -->`(`Foot.php`) 마커로 삽입 위치를 결정한다. 마커가 없는 슬롯은 기본 대역으로 폴백해 유실을 막는다.

## Front vs Admin 렌더러 차이

| 항목 | Front | Admin |
|---|---|---|
| 프레임(셸) 스킨 | `views/Front/frame/{skin}/` — theme_config `frame` | `views/Admin/frame/{skin}/` — theme_config `admin` |
| 콘텐츠 스킨 | 그룹별 스킨 차원 있음 (`views/Front/{Group}/{skin}/`) | **없음** — 단일 세트 `views/Admin/{Group}/{File}.php` |
| 조립 순서 | Head → Header → Layout → Content → Footer → Foot | Head → LayoutOpen → Sidebar → Header → Content → Footer → Foot |
| LayoutManager | 사용 (사이드바·블록 position) | 사용 안 함 |
| ViewContext Helper | `format`, `content`, `assets` + 이벤트 등록분 | `listRenderHelper`, `assets` |
| 스킨 격리 | `renderViewIsolated()` | 없음 (`ViewContext::render()` 직접) |
| 확장 이벤트 | ViewContextCreated·FrontFootRender·PageTypeResolve | 없음 |

Admin 콘텐츠에 스킨 차원이 없는 것은 의도다 — 스킨 배포 시 `views/Admin/frame/{skin}/` 폴더 하나만 복사하면 되고, 관리 화면의 HTML 구조는 내부 API라 스킨 대상이 아니다(`docs/compatibility-policy.md`).

## 프레임 스킨 규약

프레임 스킨 하나는 파트 파일의 묶음이다. 실물 기준(`views/Front/frame/basic/`):

```text
views/Front/frame/{skin}/
├── Head.php        # <html>~<head>, MUBLO_CSS 마커
├── Header.php      # 전역 상단 UI
├── LayoutOpen.php  # 본문 래퍼 시작
├── LayoutClose.php # 본문 래퍼 종료
├── Footer.php      # 전역 하단 UI
├── Foot.php        # 스크립트, MUBLO_JS 마커, </body>
└── _assets/        # 스킨 전용 CSS/JS
```

Admin 셸(`views/Admin/frame/basic/`)은 `LayoutClose.php` 대신 `Sidebar.php`를 가진다. `_assets`는 웹루트 밖이므로 `ServeController`가 서빙한다: `/serve/front/{skin}/{path}` → `views/Front/frame/{skin}/_assets/`, `/serve/admin/{skin}/{path}` → `views/Admin/frame/{skin}/_assets/` (`src/Controller/Api/ServeController.php`).

### FrameOverride — 패키지 프레임 오버라이드

Package는 자기 영역에서 코어 프레임 대신 자기 프레임을 쓸 수 있다. 규격은 `src/Core/Theme/FrameOverride.php`가 소유한다.

- 저장: `theme_config['frame_overrides']['packages'][{pkg}] = 스킨명`. 코어 프레임 선택(`theme_config['frame']`)은 건드리지 않는 별도 버킷이다. `FrameOverride::resolve()`/`apply()`는 읽기/병합만 하는 dumb 규격이고, 해석·적용은 렌더 배선이 담당한다.
- 적용: Package Provider가 자기 영역 요청에서 `Context::setFrameBasePath(디렉토리 절대경로)`를 호출한다. 실사용례 — `packages/Shop/ShopProvider.php`의 `applyFrameOverride()`는 shop 프론트 영역에서만 `FrameOverride::resolve($themeConfig, 'shop')`로 스킨명을 얻어 `MUBLO_PACKAGE_PATH . '/Shop/views/Front/frame/{skin}'`을 지정한다. 폴더가 없으면 조용히 코어 프레임 유지.
- 폴백: `FrontViewRenderer::includeFrameView()`는 파트 파일 단위로 frameBasePath를 먼저 보고, 없으면 코어 `frame/{frameSkin}/`으로 폴백한다. 패키지가 `Header.php` 하나만 제공해도 나머지 파트는 코어가 렌더한다.

### 도메인 프레임 편집 — header/footer HTML 오버라이드

도메인 운영자가 블록 에디터에서 header/footer를 HTML로 직접 편집(수동 + AI)해 파일 스킨 대신 렌더하는 기능. 설계 전문은 `storage/docs/Mublo_Domain_Frame_Editing_Implementation_Plan.md`.

- **렌더 우선순위 (파트 단위)**: Package frameBasePath → 도메인 DB 오버라이드(published) → 파일 스킨. Package가 앞서는 것은 화면 단위 발동(결제 등 구체적 의도)이기 때문이고, 일반 화면에서는 운영자 HTML이 실질 1순위다. 치환 실패 시 파일 스킨 폴백 — header가 깨져도 사이트는 산다.
- **원문 저장 원칙**: DB(`domain_frame_overrides`)에는 `{{...}}` 템플릿 원문을 저장하고 치환은 매 렌더 시점에 한다. 변수는 `htmlspecialchars` 이스케이프, 슬롯만 raw HTML. HTML 주석 안의 토큰은 치환하지 않는다.
- **템플릿 소스**: 코어 변수 14종 + 슬롯 10종(`CoreFrameTemplateSources`), 확장은 `FrameTemplateSourceCollectEvent`로 `{{확장명.이름}}` 네임스페이스 등록(지연 해석, 비활성 시 빈 문자열 소거).
- **조회 비용 0**: 게시 시 theme_config `frame_edit.parts` 플래그 갱신 — 플래그 없는 도메인은 오버라이드 쿼리를 아예 하지 않는다.
- **스킨 제작 규약 추가 — 편집 시드(선택)**: 스킨 디렉토리에 `Header.seed.html`/`Footer.seed.html`(템플릿 문자열이 든 정적 HTML)을 동봉하면 그 스킨 사용 도메인의 편집 시작점이 된다. 없으면 basic 공식 시드로 폴백(에디터가 안내). 시드에서 시작한 편집본은 스킨에서 분리된 사본(detached copy)이며, "스킨으로 되돌리기"는 비파괴(게시 해제 + 초안 보관)다.

## 스킨에서 쓰는 API — ViewContext와 ViewHelper

스킨 파일은 `ViewContext::render()` 안에서 include되므로 `$this`가 ViewContext다. 실존 메서드 기준 공개 표면:

- `$this->get('key', $default)` — 뷰 데이터 조회. `$this->getQueryString()` — 현재 쿼리스트링.
- `$mublo['site'|'viewer'|'request'|'navigation'|'theme'|'security'|'runtime']` — 모든 Front 스킨의 예약 공통 데이터. 상세 규격은 [Front 스킨 데이터 계약](../reference/front-view-data-contract.md).
- `$this->component('pagination', $data)` — `views/Components/{name}.php` 렌더. `$this->pagination($pagination)`, `$this->menu($menus, $activeCode)`는 그 래퍼다.
- `$this->category('shop', $depth)` — Package가 `CategoryProviderRegistry`에 등록한 카테고리 트리 조회(미등록 시 빈 배열). 헤더 내비게이션에서 쓰는 대표 API.
- `$this->layout(['header' => false])` — 레이아웃 힌트 선언 (Front 전용 의미). 키: `header`/`footer`(bool), `standalone`(bool), `layout`(`'full'|'left'|'right'|'both'` — 사이드바 레이아웃 직접 선언). `header => false`면 `layout` 명시가 없는 한 사이드바 없는 full로 강제된다.
- `$this->format->number()`, `->relativeTime()`, `->maskName()`, `->bytes()`, `->highlightKeyword()` — 포맷팅.
- `$this->content->thumbnail()`, `->summary()`, `->imageCount()` — 본문 파싱.
- `$this->assets->addCss($path, $slot)`, `->addJs($path, $slot)` — 에셋 등록.
- Admin 한정: `$this->columns()`(ListColumnBuilder 팩토리), `$this->listRenderHelper`.

Helper는 `setHelper($name, $object)`로 주입되고 `$this->{name}`으로 접근한다(`__get`). 확장이 등록한 Helper도 같은 문법이다.

## 확장 개입 지점 4종

| 이벤트 | 발행 시점 | 개입 내용 | 실사용례 |
|---|---|---|---|
| `RendererResolveEvent` | `Application::handleResponse()`, 렌더 직전 | `setRenderer()`로 커스텀 렌더러 지정 — 기본 Admin/Front 분기 대체. 호출 시 전파 자동 중단(렌더러는 하나) | URL 패턴별 독자 파이프라인 (소스 docblock 예: `/dashboard`) |
| `ViewContextCreatedEvent` | `FrontViewRenderer`, ViewContext 생성 직후 | `getViewContext()->setHelper('shop', ...)` — 스킨에서 `$this->shop->price(...)` 사용 가능 | 소스 docblock의 ShopViewSubscriber 패턴 |
| `SiteContextReadyEvent` | `Application`, 세션 시작 후·Router 실행 전 | `Context::setSiteImageUrl()`/`setSiteLogoText()`로 사이트 표시 override. 세션 값 접근 가능 시점 | mshop 파트너별 로고 교체 (소스 docblock의 PartnerLogoSubscriber) |
| `FrontFootRenderEvent` | `FrontViewRenderer`, Footer 후·`</body>` 전 | `addHtml()`로 프론트 HTML 주입 (순서대로 출력) | Popup 플러그인의 팝업 출력 |

모두 `src/Core/Event/Rendering/`에 있다. 이벤트 구독 방법 자체는 [08. Event](08-event.md)를 본다. `FrontFootRenderEvent`는 `docs/compatibility-policy.md`의 대표 안정 이벤트 목록에 올라 있다.

## 다크모드

라이트/다크/자동 토글은 `public/assets/js/theme.js`가 담당한다 — 선택을 `localStorage`에 저장하고 `<html>`의 `data-bs-theme` 속성을 갱신한다. 현재 로드 위치는 Admin 셸(`views/Admin/frame/basic/Head.php`)이며, Front basic 프레임의 CSS는 자체 토큰 시스템을 쓰고 theme.js에 의존하지 않는다(`views/Front/frame/basic/_assets/css/front.css` 주석).

## 경계 — 스킨 제작자를 위한 공개 표면

`docs/compatibility-policy.md` 기준으로 이 장의 안정/내부 경계는 다음과 같다.

- **안정**: `Mublo\Core\Rendering\*`, `Mublo\Core\Theme\*`(FrameOverride 포함), `Mublo\Core\Event\Rendering\*`의 이벤트, `Mublo\Helper\*`(format/content Helper 포함). 스킨이 의존해도 되는 것은 이 표면과 위 "스킨에서 쓰는 API" 절의 메서드다.
- **내부**: Renderer 내부 구현 세부(조립용 protected 메서드, 버퍼 처리), 관리자 화면의 HTML 구조. 스킨에서 코어 Service/Repository를 직접 호출하는 것은 [32. Anti Pattern](32-anti-pattern.md)이다 — 데이터는 Controller가 넘기고, 스킨은 출력만 한다.

**Best Practice** — 커스텀 콘텐츠 스킨은 바꿀 파일만 만들어라. per-file 폴백이 나머지를 책임진다. 패키지 프레임도 같다: 전 파트 복사 대신 필요한 파트만 오버라이드.

**Anti Pattern** — 스킨 안에서 `ob_start()`를 열고 닫지 않는 것(격리 계층이 복원하지만 의도가 아니다), 프레임 스킨에서 `MUBLO_CSS`/`MUBLO_JS` 마커를 빼먹는 것(에셋이 폴백 대역으로만 몰린다).

## 관련 문서

- [스킨 제작 튜토리얼](../dev-guide/skin-development.md) — 콘텐츠·프레임·블록 스킨 실습
- [07. Response](07-response.md) — ViewResponse 계약과 렌더러 결정 지점
- [04. Context](04-context.md) — 요청 Context와 도메인 해석
- [08. Event](08-event.md) — 이벤트 구독 방법
- [17. 블록 시스템](17-block-system.md) — 블록 스킨과 position 렌더
- `docs/reference/hook-points.md`, `docs/reference/event-reference.md` — 렌더링 이벤트 코드 예제
- `docs/compatibility-policy.md` — 안정 API 경계의 진실
