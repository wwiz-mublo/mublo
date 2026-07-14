# 25. 검색·마이페이지·메뉴

통합 검색, 마이페이지, 사이트 메뉴는 서로 다른 화면이지만 확장 구조의 원리는 하나다 — **코어가 액자(라우트·셸·정렬·저장)를 소유하고, Package가 그림(결과·섹션·메뉴 항목)을 공급한다.** 코어 고정 섹션인 프로필·포인트·회원 알림·탈퇴는 액자 안에 직접 포함되고, Package 섹션만 공개 Event를 통해 추가된다. 이 장은 그 경계와 Board 패키지의 실제 연결 방식을 서술한다.

이 장이 다루는 기능:

| 기능 | 소스 |
|---|---|
| 통합 검색 | `src/Controller/Front/SearchController.php` (index) |
| `GET /search` 라우트 | `src/Core/App/Router.php` |
| SearchEvent — 통합 검색 실행(검색 결과 공급) | `src/Core/Event/Search/SearchEvent.php` |
| SearchSourceCollectEvent — 검색 소스 수집 | `src/Core/Event/Search/SearchSourceCollectEvent.php` |
| 마이페이지 프로필 조회·수정(커스텀 필드 파일 포함) | `src/Controller/Front/MypageController.php` (index, profile, updateProfile, uploadFieldFile) |
| 회원 알림 목록·열기·전체 읽음 | `src/Controller/Front/MypageController.php` (notifications, openNotification, markAllNotificationsRead) |
| 패키지 마이페이지 섹션·허브 렌더 (`/mypage/{provider}[/{section}]`) | `src/Controller/Front/MypageSectionController.php` (view, hub) |
| `/mypage`, `/mypage/profile`, `/mypage/balance`, `/mypage/withdraw` 고정 섹션 라우트 | `src/Core/App/Router.php` |
| `/mypage/notifications`, `/mypage/notifications/open`, `/mypage/notifications/read-all` 알림 라우트 | `src/Core/App/Router.php` |
| `GET /mypage/{provider}`, `GET /mypage/{provider}/{section}` 제네릭 라우트 | `src/Core/App/Router.php` |
| MypageSectionBuildingEvent — 마이페이지 섹션 등록 | `src/Core/Event/Mypage/MypageSectionBuildingEvent.php` |
| 사이트 메뉴 트리 관리(항목 CRUD·트리 편집) | `src/Controller/Admin/MenuController.php` (index, itemStore, itemDelete, itemView, treeUpdate, treeAdd, treeRemove, listModify) |
| 유틸리티/푸터/마이페이지 메뉴 설정 | `src/Controller/Admin/MenuController.php` (utilityUpdate, footerUpdate, mypageUpdate) |

## 개요 — 액자와 그림

세 서브시스템의 공급 통로는 각각 다음과 같다.

| 지점 | 액자(코어) | 그림(Package) | 통로 |
|---|---|---|---|
| 통합 검색 | `/search` 라우트, 결과 정렬·페이지 렌더 | 소스별 검색 결과 | `SearchEvent` + `SearchSourceCollectEvent` 구독 |
| 마이페이지 | `/mypage/*` 라우트, 셸(사이드바+콘텐츠) | 허브·섹션(뷰 + 데이터) | `MypageSectionBuildingEvent` 구독 |
| 메뉴 | menu_items·menu_tree 저장, 관리 화면, URL 매칭 | 메뉴 항목 행 | `MenuService::createItem()` 직접 호출 |

검색과 마이페이지는 Event 구독으로, 메뉴는 코어 서비스 호출로 공급한다. 메뉴가 Event 방식이 아닌 이유는 메뉴 항목이 요청마다 재구성되는 것이 아니라 DB(`menu_items`)에 저장되는 영속 데이터이기 때문이다 — `src/Service/Mypage/MypageMenuBuilder.php` 주석이 이를 명시한다("패키지 설치 시 menu_items에 INSERT, 이벤트 방식 불사용").

## 통합 검색

### 실행 흐름

`GET /search?q=키워드`(`src/Core/App/Router.php`)는 `src/Controller/Front/SearchController.php`의 `index()`로 들어온다. 컨트롤러는 `DomainSettingsService::getSiteConfig()`로 도메인 설정을 읽고 `src/Service/Search/SearchService.php`의 `search()`를 호출한다. `SearchService`가 하는 일이 곧 액자의 전부다.

1. `SearchEvent`를 생성해 발행한다. 이벤트에는 키워드·도메인 ID와 함께 site_config의 세 키가 실린다 — `search_source_order`(소스 표시 순서), `search_enabled_sources`(활성 소스: `null`=미설정 전체 활성 / `[]`=전체 해제 / 목록=명시 활성), `search_per_source`(소스별 최대 결과 수, 기본 5).
2. 각 구독자(Package)가 `addResults()`로 자기 소스의 결과를 추가한다.
3. `SearchService`가 `search_source_order` 순서로 결과 그룹을 정렬하고, 비활성 소스를 제외한다. 순서 목록에 없는 소스의 결과는 뒤에 붙인다.

결과는 `views/Front/Search/basic/Index.php`가 그룹 단위로 렌더한다. 코어는 어떤 소스가 존재하는지 모른 채 그룹 배열만 순회한다.

### 결과 포맷 규약

구독자가 호출하는 `SearchEvent::addResults(string $source, string $sourceLabel, array $items, int $total, array $options = [])`(`src/Core/Event/Search/SearchEvent.php`)가 결과 포맷의 진실이다.

- `$items`의 각 항목: `['title' => string, 'url' => string, 'summary' => ?string, 'thumbnail' => ?string, 'date' => ?string, 'meta' => ?string]`. 항목 수는 이벤트가 `perSource`로 잘라낸다(`array_slice`).
- `$total`: 해당 소스의 전체 결과 수(표시 건수와 별개).
- `$options['view_path']`: 소스 전용 렌더 파일 절대 경로. 지정하면 기본 목록 템플릿 대신 이 파일로 항목을 렌더한다(`views/Front/Search/basic/Index.php`가 분기).
- `$options['more_url']`: "더보기" 링크 URL.

구독자는 결과 추가 전에 `isSourceEnabled($source)`로 자기 소스의 활성 여부를 확인한다. 이 메서드는 빈 배열을 전체 활성으로 해석하지만, 설정값의 `[]`는 전체 해제를 뜻한다. 그래서 `SearchService::search()`는 설정이 명시적인 빈 배열이면 `SearchEvent`를 발행하지 않고 즉시 빈 결과를 반환한다. 미설정 `null`만 전체 활성으로 처리한다.

### 검색 소스 등록 — SearchSourceCollectEvent

관리자 기본 설정 화면(`src/Controller/Admin/SettingsController.php`의 "검색 설정" 탭)은 어떤 소스가 존재하는지 알아야 체크박스를 그릴 수 있다. 이를 위해 `src/Service/Domain/DomainSettingsService.php`의 `getAvailableSearchSources()`가 `SearchSourceCollectEvent`를 발행하고, 각 Package가 `addSource(string $source, string $label, bool $always = false, string $group = 'package')`로 자기 소스를 등록한다. 같은 이벤트는 설정 저장 시 허용 목록 검증(`sanitizeSearchSourceList()`)에도 쓰인다 — 등록되지 않은 소스 식별자는 저장 단계에서 걸러진다.

### Board의 실구현

`packages/Board/Subscriber/BoardSearchSubscriber.php`는 두 이벤트를 모두 구독한다(`packages/Board/BoardProvider.php`에서 등록).

```php
public static function getSubscribedEvents(): array
{
    return [
        SearchSourceCollectEvent::class => 'onCollect',   // addSource('board', '게시판')
        SearchEvent::class              => 'onSearch',
    ];
}

public function onSearch(SearchEvent $event): void
{
    if (!$event->isSourceEnabled('board')) {
        return;
    }
    $total = $this->articleRepository->countByKeyword($event->getDomainId(), $event->getKeyword());
    if ($total === 0) {
        return;
    }
    $items = $this->articleRepository->searchByKeyword(
        $event->getDomainId(), $event->getKeyword(), $event->getPerSource()
    );
    $event->addResults('board', '게시판', $items, $total, [
        'more_url' => '/community?keyword=' . rawurlencode($event->getKeyword()),
    ]);
}
```

Repository 접근은 Board 내부의 일이고, 코어와의 접점은 `addResults()`의 포맷 규약뿐이다.

## 마이페이지

### 허브·섹션 구조

마이페이지 라우트는 두 층이다(`src/Core/App/Router.php`).

- **고정 섹션(코어 소유)** — `/mypage`(→ `/mypage/profile` 리다이렉트), `/mypage/profile`, `/mypage/balance`, `/mypage/notifications`, `/mypage/withdraw`. `src/Controller/Front/MypageController.php`가 처리한다.
- **패키지 섹션(제네릭)** — `GET /mypage/{provider}`(허브)와 `GET /mypage/{provider}/{section}`(세부 섹션). 정적 라우트보다 뒤에 등록되어 고정 섹션이 우선 매칭된다. `src/Controller/Front/MypageSectionController.php`가 처리한다.

### 코어 회원 알림함

회원 알림함은 Package가 `MypageSectionBuildingEvent`로 공급하는 섹션이 아니라 Core 고정 섹션이다. `MypageController::notifications()`가 현재 도메인과 로그인 회원 ID로 `MemberNotificationService::paginate()`를 호출하고 페이지당 20건과 미읽음 수를 렌더한다.

`openNotification()`은 POST의 `notification_id`를 받아 같은 도메인·회원 소유 알림만 조회하고 읽음 처리한다. 저장된 `target_url`이 안전한 내부 상대 경로가 아니면 `/mypage/notifications`로 돌아간다. `markAllNotificationsRead()`도 현재 도메인·회원 범위만 갱신한다. 알림의 발행 계약과 Board 댓글 소비 사례는 [24. 알림](24-notification.md)에서 다룬다.

### MypageSectionBuildingEvent — Package의 섹션 공급

`src/Core/Event/Mypage/MypageSectionBuildingEvent.php`가 공급 API다. 구독자는 먼저 `setSource($provider)`로 자기 네임스페이스를 지정한 뒤 두 종류를 등록한다.

- `registerHub(string $label, string|callable $viewPath, callable $dataProvider)` — 패키지당 1개, `/mypage/{provider}`에 렌더되는 요약 허브. `$dataProvider`는 `fn(int $memberId, int $domainId): array`. `$viewPath`에 콜백(`fn(int $domainId): string`)을 넘기면 렌더 시점에 스킨 경로가 해석된다.
- `registerSection(string $key, string $label, string $viewPath, callable $dataProvider)` — `/mypage/{provider}/{key}`의 세부 섹션. `$dataProvider`는 `fn(int $memberId, int $domainId, int $page, int $perPage): array`.

이벤트는 요청마다 매번 발행되지 않는다. `src/Service/Mypage/MypageSectionRegistry.php`가 첫 조회 시 1회 발행해 결과를 요청 스코프에 캐시한다.

### 렌더 — provider 파라미터 라우팅

`MypageSectionController::view()`는 URL의 `{provider}`/`{section}` 파라미터로 레지스트리에서 섹션을 찾고, 없으면(패키지 비활성·오타) `/mypage`로 리다이렉트한다. 찾으면 `dataProvider`를 호출해 데이터를 만들고, 코어 셸 `views/Front/Mypage/basic/Section.php`에 패키지의 콘텐츠 partial(`viewPath`)을 끼워 렌더한다. 셸은 partial을 `include`하고 사이드바 `_layout`으로 감쌀 뿐, 섹션 내용을 알지 못한다. `hub()`도 같은 구조다. 사이드바 메뉴는 `src/Service/Mypage/MypageMenuBuilder.php`가 `menu_items.show_in_mypage=1` 행에서 빌드하며, 확장 제공 항목의 아이콘은 해당 확장의 `manifest.json` `icon`을 읽는다.

### Board의 실구현

`packages/Board/Subscriber/MypageSectionSubscriber.php`가 허브 1개("마이보드" — 게시판별 글·댓글 통계와 30일 활동 추이)와 섹션 2개를 등록한다.

```php
$event->setSource('board');
$event->registerSection(
    'articles', '내가 쓴 글',
    $this->skinView($viewDir, 'Articles.php'),          // packages/Board/views/Front/Mypage/basic/
    function (int $memberId, int $domainId, int $page, int $perPage): array {
        $r = $this->articleRepository->getByMember($memberId, $domainId, $page, $perPage);
        return ['articles' => $r['items'], 'pagination' => $r['pagination']];
    }
);
```

이로써 `/mypage/board`, `/mypage/board/articles`, `/mypage/board/comments`가 코어 라우트 추가 없이 생긴다. 코어의 고정 섹션이었던 "내가 쓴 글/댓글"은 Board 패키지 섹션으로 이관되었다(`src/Core/App/Router.php` 주석).

## 메뉴

### MenuService의 역할

`src/Service/Menu/MenuService.php`는 도메인별 메뉴의 저장 계층이다. 메뉴는 두 테이블로 나뉜다 — **menu_items**(항목: 라벨·URL·노출 조건·제공자)와 **menu_tree**(메인 메뉴 배치: `>` 구분 `path_code` 경로 트리). 하나의 항목은 트리 배치와 별개로 `show_in_utility`/`show_in_footer`/`show_in_mypage` 플래그로 유틸리티·푸터·마이페이지 영역에도 노출될 수 있다. 주요 규약:

- 항목 생성 시 `menu_code`를 `CodeGenerator`(`unique_codes` 테이블)로 발급한다.
- 항목은 `provider_type`(`core`/`package`/`plugin`)과 `provider_name`으로 출처를 기록한다. 관리 화면의 제공자 필터와 마이페이지 아이콘 해석이 이를 사용한다.
- `pair_code`는 짝 항목 자동 포함 규약이다 — 유틸리티 메뉴 저장 시 로그인(guest)만 선택해도 같은 `pair_code`의 로그아웃(member)이 자동 포함된다(`expandWithPairedItems()`).
- 신규 도메인에는 `seedDefaultMenus()`가 코어 기본 메뉴(홈·로그인·마이페이지·약관 등)를 시딩한다. 커뮤니티·내가 쓴 글/댓글 메뉴는 코어가 아니라 Board 패키지가 시딩한다(주석 명시).

관리 화면은 `src/Controller/Admin/MenuController.php`와 `views/Admin/Menu/`(Index.php + tab-items·tab-tree·tab-utility·tab-footer·tab-mypage 탭)다. 항목 CRUD(itemStore/itemDelete/itemView/listModify), 트리 편집(treeUpdate/treeAdd/treeRemove), 영역별 포함·순서 저장(utilityUpdate/footerUpdate/mypageUpdate)을 제공한다. 마이페이지 영역의 시스템 메뉴(회원정보/회원탈퇴)는 sentinel 순서(0/9999)로 양끝에 고정되어 운영자 저장으로 바뀌지 않는다(`saveMypageOrder()`).

### 메뉴 코드와 Context의 연결

메뉴는 화면 출력만이 아니라 **요청 해석**에도 쓰인다. `src/Core/Context/ContextBuilder.php`가 요청 URL을 도메인의 메뉴 URL 맵(캐시 키 `menu:urlmap:{domainId}`)과 매칭해 — path+query 일치, path 일치, 최장 prefix 순 — `Context::setCurrentMenuCode()`로 현재 메뉴 코드를 확정한다. 이 값은 블록 시스템의 메뉴 스코프와 프론트 메뉴 active 표시가 사용하며, `Context::getCurrentMenuCode()`로 읽는다. 상세는 [04. Context](04-context.md)를 본다. `MenuService`는 항목 생성·수정·삭제 시마다 이 캐시를 무효화한다(`invalidateUrlMapCache()`).

### 메뉴 자동 등록 — 이벤트의 구독 측 실체

[17. 블록 시스템](17-block-system.md)이 발행 측을 다룬 `BlockPageCreatedEvent`/`BlockPageDeletedEvent`의 구독자가 `src/Subscriber/BlockPageMenuSubscriber.php`다(`src/Core/Provider/ServiceProvider.php`에서 등록). 블록 페이지가 생성되면 `MenuService::createItem()`으로 `/p/{code}` 메뉴 항목을 자동 등록하고(`provider_type='core'`, `provider_name='blockpage'`), 삭제되면 같은 provider의 항목 중 URL이 일치하는 행을 자동 삭제한다. 등록은 menu_items까지이며 menu_tree 배치는 관리자의 수동 작업이다.

Package도 같은 패턴을 쓴다. `packages/Board/Subscriber/MenuAutoRegistrationSubscriber.php`는 게시판 생성/삭제 이벤트(`BoardConfigCreatedEvent`/`BoardConfigDeletedEvent`)를 구독해 `/board/{slug}` 메뉴 항목을 `provider_type='package'`, `provider_name='Board'`로 자동 등록·삭제한다. 메뉴가 Event 공급이 아니라고 했지만, **자기 도메인 이벤트에 반응해 MenuService를 호출하는 것**이 확장의 표준 메뉴 등록 경로다.

## 확장 개발자 규약 요약

| 하고 싶은 것 | 구독/호출 대상 | 구현 요점 |
|---|---|---|
| 통합 검색에 결과 노출 | `SearchSourceCollectEvent` + `SearchEvent` 구독 | `onCollect`에서 `addSource()`로 소스 등록(관리자 설정 노출), `onSearch`에서 `isSourceEnabled()` 확인 후 `addResults()`. 항목 포맷은 title/url/summary/thumbnail/date/meta |
| 마이페이지에 화면 추가 | `MypageSectionBuildingEvent` 구독 | `setSource(provider)` 후 `registerHub()`(요약 1개) / `registerSection()`(세부). 뷰 파일은 패키지 소유 절대 경로, 데이터는 dataProvider 콜백 |
| 메뉴 항목 추가 | `MenuService::createItem()` 직접 호출 | 자기 도메인 이벤트(생성/삭제)에 구독자를 붙여 등록·삭제. `provider_type`/`provider_name` 필수 기재, 트리 배치는 관리자 몫 |

세 지점 모두 코어 수정이 필요 없고, Package가 비활성화되면 그림만 사라진다 — 검색 그룹이 빠지고, 마이페이지 섹션은 `/mypage`로 리다이렉트되며, 메뉴 항목은 삭제 이벤트로 정리된다.

## 관련 문서

- [04. Context](04-context.md) — 현재 메뉴 코드 해석과 요청 스코프
- [08. Event](08-event.md) — EventDispatcher와 Subscriber 등록 규약
- [17. 블록 시스템](17-block-system.md) — BlockPageCreated/DeletedEvent의 발행 측
- [29. Package Guide](29-package-guide.md) — Provider에서 Subscriber를 등록하는 위치
- [33. Reference Packages](33-reference-packages.md) — Board 패키지 전체 해설
