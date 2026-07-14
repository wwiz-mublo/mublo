# 22. 관리자 대시보드 위젯

관리자 대시보드(`GET /admin`)는 코어가 소유한 화면이지만, 그 위에 올라가는 카드들은 레지스트리 기반이다. 이 장의 중심 질문은 하나다 — **Plugin/Package 제작자는 어떻게 자기 위젯을 관리자 첫 화면에 보내는가.** 운영 화면 사용법이 아니라 확장 등록 경로를 서술한다.

이 장이 다루는 기능: 관리자 대시보드 위젯 렌더(`src/Controller/Admin/DashboardController.php`의 `index`), 위젯 배치 관리 — 숨김/표시/이동/재정렬/레이아웃 초기화(동 컨트롤러의 `hideWidget`, `showWidget`, `moveWidget`, `reorderWidgets`, `resetLayout`), 대시보드 진입 라우트 `GET /admin`과 배치 API `POST /admin/dashboard/widget/*`·`/admin/dashboard/layout/*`(`src/Core/App/Router.php`), 관리자 메뉴 확장 이벤트 `AdminMenuBuildingEvent`(`src/Service/Admin/Event/AdminMenuBuildingEvent.php`).

## 개요

구조는 세 조각이다.

- **`DashboardWidgetRegistry`** (`src/Core/Dashboard/DashboardWidgetRegistry.php`) — 위젯 인스턴스의 등록처. Provider `boot()`에서 `register()`를 호출하는 것이 확장의 유일한 진입점이다.
- **`DashboardLayoutManager`** (`src/Core/Dashboard/DashboardLayoutManager.php`) — 운영자별 배치 상태(숨김·순서·슬롯)를 DB(`DashboardLayoutRepository`)로 오버라이드한다. 제작자가 정한 것은 어디까지나 기본값이다.
- **`DashboardController`** (`src/Controller/Admin/DashboardController.php`) — 렌더 파이프라인. Registry 전체 수집 → `canView(Context)` 권한 필터 → 사용자 레이아웃 로드(sanitize 포함) → 숨김 제외 → 모드별 그리드 정렬 → 위젯별 렌더(에러 격리) → 에셋 수집 순서로 `views/Admin/Dashboard/Index.php`에 넘긴다.

위젯 하나가 죽어도 대시보드는 죽지 않는다. `DashboardController::renderGrid()`가 `render()` 호출을 try/catch로 감싸 실패한 카드만 안내 문구로 대체하고 `Logger`에 경고를 남긴다.

## 위젯 등록 절차

### 1. 계약 — DashboardWidgetInterface 6메서드

`src/Core/Dashboard/DashboardWidgetInterface.php`:

```php
interface DashboardWidgetInterface
{
    public function id(): string;                    // 위젯 고유 ID (예: 'core.system_info')
    public function title(): string;                 // 표시 제목
    public function render(): string;                // HTML 렌더링
    public function assets(): array;                 // 외부 CSS/JS 에셋
    public function defaultSlot(): int;              // 기본 슬롯 크기 (1~4). DB에서 오버라이드 가능
    public function canView(Context $context): bool; // 이 위젯을 볼 수 있는지 권한 체크
}
```

### 2. 베이스 클래스 — AbstractDashboardWidget

전부 직접 구현할 필요는 없다. `src/Core/Dashboard/AbstractDashboardWidget.php`가 `assets() = []`, `defaultSlot() = 2`, `canView() = true`를 기본 제공하므로, 최소 구현은 `id()`·`title()`·`render()` 세 개다. 코어의 `SystemInfoWidget`·`MemberStatsWidget`(`src/Core/Dashboard/Widget/`)과 Board의 `RecentNoticesWidget`이 모두 이 베이스를 상속한다.

### 3. 등록 — Provider boot()에서 register()

`DashboardWidgetRegistry::register(string $id, DashboardWidgetInterface $widget, int $priority = 50)`. Board 패키지의 실제 등록부(`packages/Board/BoardProvider.php`의 `boot()`):

```php
// 대시보드 위젯 등록
$registry = $container->get(DashboardWidgetRegistry::class);
$domainIdResolver = fn() => $container->has(Context::class) ? $container->get(Context::class)->getDomainId() : null;
$registry->register(
    'board.recent_notices',
    new RecentNoticesWidget($container->get(BoardArticleRepository::class), $domainIdResolver),
    10
);
```

`priority`는 낮을수록 앞자리다. 코어는 `core.system_info`를 0, `core.member_stats`를 1로 등록하고(`src/Core/Provider/ServiceProvider.php`), Board는 10을 쓴다. 같은 `$id`로 다시 등록하면 배열 키가 같으므로 덮어쓴다.

### 4. ID 규약 — 접두사가 곧 출처

`DashboardWidgetRegistry::detectSource()`가 ID 접두사로 출처를 판별한다: `core.`으로 시작하면 core, `plugin.`이면 plugin, 그 외는 전부 package다. 이 출처는 숨긴 위젯 복원 목록(`DashboardLayoutManager::getHiddenWidgets()`)에서 운영자에게 표시된다. Package 제작자는 `{패키지소문자}.{위젯명}`(예: `board.recent_notices`), 독립 Plugin 제작자는 `plugin.{이름}` 형태를 쓴다.

## 배치와 권한

### AUTO 배치 — priority 순 4슬롯 그리드

운영자가 손대기 전의 기본 배치는 `SlotGridArranger`(`src/Core/Dashboard/SlotGridArranger.php`)가 만든다. priority 오름차순으로 정렬한 뒤 4슬롯(`MAX_SLOTS = 4`) 행에 순서대로 채우고, 남은 슬롯이 부족하면 다음 행으로 넘긴다(순서 보존 — Greedy Fill 미적용). 슬롯 크기는 Bootstrap 컬럼 클래스로 매핑된다(1 → `col-lg-3` … 4 → `col-12`). 제작자가 배치에 영향을 줄 수 있는 수단은 `priority`와 `defaultSlot()` 둘뿐이다.

### 운영자 오버라이드 — 3가지 모드

`DashboardLayoutManager::getMode()`가 DB 상태로 모드를 판단한다: 레코드 없음 = `AUTO`, 숨김만 있음 = `AUTO_OVERRIDE`, row/col 위치 있음 = `MANUAL`. 운영자가 이동·재정렬을 처음 수행하면 현재 자동 배치가 DB화되고(`autoToManual()`), 이후 `POST /admin/dashboard/widget/move`·`/admin/dashboard/layout/reorder`로 위치가 갱신된다. `resetLayout()`은 사용자 레코드를 지워 AUTO로 복귀시킨다. 배치는 `(domainId, userId)` 단위 — 도메인마다, 운영자마다 다르다.

제작자 관점에서 중요한 동작 두 가지:

- **신규 위젯 자동 삽입** — MANUAL 사용자에게도 새로 등록된 위젯은 마지막 행 아래에 자동 추가되어 DB에 영속화된다(`DashboardLayoutManager::appendNewWidgets()`). 패키지를 나중에 설치해도 위젯이 묻히지 않는다.
- **제거 자동 정리** — 패키지가 비활성화되어 Registry에서 사라진 `widget_id`는 렌더 전에 `LayoutSanitizer`(`src/Core/Dashboard/LayoutSanitizer.php`)가 레이아웃에서 제거하고 row 번호를 0부터 연속으로 정규화한다. 제거 대응 코드를 위젯 쪽에 쓸 필요가 없다.

### 위젯 단위 권한 — canView(Context)

`DashboardController::index()`가 레이아웃을 적용하기 전에 `canView($context)`가 false인 위젯을 걸러낸다. 코어 `SystemInfoWidget`은 이렇게 대표 도메인에만 노출을 제한한다(`src/Core/Dashboard/Widget/SystemInfoWidget.php`):

```php
public function canView(Context $context): bool
{
    return ($context->getDomainId() ?? 1) === 1;
}
```

관리자 레벨·도메인 운영자 판정 등 권한 모델 전반은 [20. 권한 모델](20-permission-model.md)에서 다룬다.

## 관리자 메뉴 확장 — AdminMenuBuildingEvent

위젯이 대시보드 본문이라면, 관리자 사이드바 메뉴는 `AdminMenuBuildingEvent`로 확장한다. `AdminMenuService::getMenus()`(`src/Service/Admin/AdminMenuService.php`)가 코어 메뉴를 먼저 채우고 이벤트를 발행하면 Package/Plugin 구독자가 메뉴를 추가하고, 마지막에 지연 작업(`addSubmenuTo`, `insertBefore`/`insertAfter` 등)이 처리된다.

메뉴 코드 체계(`src/Service/Admin/Event/AdminMenuBuildingEvent.php` 상단 주석): Core는 숫자(`003_001`), Plugin은 `P_{PluginName}_{code}`, Package는 `K_{PackageName}_{code}`. `setSource()`를 먼저 호출하면 `addPackageMenu()`/`addPluginMenu()`가 접두사를 자동 적용하고, 코드 중복 시 `RuntimeException`으로 즉시 실패시킨다.

Package 실례 — Board(`packages/Board/Subscriber/AdminMenuSubscriber.php`, `boot()`에서 `addSubscriber`로 등록):

```php
public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
{
    $event->setSource('package', 'Board');

    // 아이콘은 manifest.json(icon) 단일 소스 — null 전달로 자동 해석
    $event->addPackageMenu('Mublo Board', null, [
        ['label' => '대시보드',    'url' => '/admin/board/dashboard', 'code' => '000'],
        ['label' => '게시판 그룹', 'url' => '/admin/board/group',     'code' => '002'],
        // ...
    ]);
}
```

종속 Plugin 실례 — BoardReport는 자기 메뉴 그룹을 만들지 않고 부모 패키지 메뉴(루트 컨테이너 코드 `K_Board`) 아래에 항목 하나를 끼워 넣는다(`packages/Board/Plugins/BoardReport/Subscriber/AdminMenuSubscriber.php`):

```php
$event->setSource('plugin', 'BoardReport');
$event->addSubmenuTo('K_Board', [
    'label' => '신고 관리',
    'url'   => '/admin/board/report/list',
    'code'  => '001',
]);
```

`AdminMenuService`는 빌드 결과를 캐시하고, `getFilteredMenus()`로 관리자 레벨별 차단 메뉴와 `super_only` 항목을 걸러 사이드바에 내보낸다. 확장이 추가한 메뉴도 같은 필터링을 통과하므로, 별도 권한 코드를 메뉴 쪽에 쓸 필요가 없다.

## Best Practice

- **`render()`는 가볍게.** 대시보드는 관리자가 로그인 후 처음 보는 화면이고, 모든 위젯의 `render()`가 이 한 요청 안에서 실행된다. 에러 격리는 있지만 지연 격리는 없다 — 느린 쿼리 하나가 첫 화면 전체를 늦춘다. `RecentNoticesWidget::fetchNotices()`처럼 조회 실패 시 빈 배열을 돌려주는 방어도 함께 둔다.
- **도메인 격리 — 지연 resolver 패턴.** `register()`가 실행되는 `boot()` 시점에는 요청 Context가 확정 전일 수 있다. Board는 도메인 ID를 즉시 읽지 않고 `fn() => $container->get(Context::class)->getDomainId()` 클로저를 주입해 `render()` 시점에 해석한다. 위젯 데이터 조회는 반드시 이 도메인으로 좁힌다([09. 멀티 도메인](09-multi-domain.md)).
- **`assets()`는 `['type' => 'style'|'script', 'src' => ...]` 배열.** `DashboardController::index()`가 `type === 'style'`이면 스타일, 그 외는 스크립트로 분류해 `src` 기준 중복 제거 후 뷰에 전달한다. 코어·Board의 번들 위젯은 전부 빈 배열을 쓴다 — 대시보드 공용 스타일(Bootstrap) 범위에서 해결되면 에셋을 추가하지 않는 편이 낫다.
- **출력은 직접 이스케이프.** `render()`가 돌려준 HTML은 그대로 출력된다. 번들 위젯처럼 사용자 데이터에 `htmlspecialchars`를 적용한다.

## 용어 경계

이 장의 "관리자 대시보드 위젯"은 이름이 비슷한 두 개념과 무관하다.

- **프론트 위젯 플러그인**(`plugins/Widget/`) — 방문자 화면에 노출되는 위젯의 CRUD·정렬을 담당하는 별개 확장이다.
- **블록 콘텐츠 타입** — 블록 시스템의 칸에 들어가는 출력 단위다([17. 블록 시스템](17-block-system.md)).

공개 표면 기준으로 `Mublo\Core\Dashboard\*` 네임스페이스 전체가 안정 API다(`docs/compatibility-policy.md`의 "관리자·렌더 확장 지점"). 반면 `DashboardController`, `views/Admin/Dashboard/Index.php`, `DashboardLayoutRepository`의 스키마는 내부 구현이며 예고 없이 바뀔 수 있다([15. Public API](15-public-api.md)).

## 관련 문서

- [08. Event](08-event.md) — `AdminMenuBuildingEvent` 구독의 전제인 이벤트 규약
- [11. Package](11-package.md) · [12. Plugin](12-plugin.md) — Provider `boot()`의 생명주기 위치
- [29. Package Guide](29-package-guide.md) — 패키지 디렉토리에서 `Widget/`의 자리
- `docs/dev-guide/package-development.md` — 위젯 등록을 포함한 패키지 개발 흐름
- `docs/reference/hook-points.md` — 확장 지점 목록 중 "대시보드 위젯 확장" 절
