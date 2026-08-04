# 17. 블록 시스템

블록 시스템은 운영자가 코드 배포 없이 관리자에서 페이지를 조립하게 하는 편집 계층이다. 이 장의 중심 질문은 하나다 — **Plugin/Package 제작자는 어떻게 자기 콘텐츠를 블록에 노출하는가.** 개입 수준은 4단계로 나뉘며, 가벼운 것부터 깊은 것 순으로 서술한다.

이 장이 다루는 기능: 블록 행/칸 CRUD·운영·미리보기(`src/Controller/Admin/BlockRowController.php`), 블록페이지 CRUD(`src/Controller/Admin/BlockPageController.php`), 블록 에디터 화면(`src/Controller/Admin/BlockEditorController.php`), 블록킷 열람·업로드·적용·롤백(`src/Controller/Admin/BlockKitController.php`), 프론트 렌더(`GET /`, `GET /p/{code}`), 블록 이벤트 7종(`src/Core/Event/Block/`).

## 개요

### 구성 요소

```text
BlockPage (페이지, /p/{code})
  └── BlockRow (가로 행 — 페이지 또는 position에 소속)
        └── BlockColumn (칸 — 콘텐츠 타입 + 스킨 + 설정 + 아이템)
```

- **BlockPage** — 하나의 페이지. 레이아웃·SEO·접근 레벨을 가진다. `src/Service/Block/BlockPageService.php`
- **BlockRow** — 가로 행. 페이지 소속이거나 위치(position) 소속이다. 유효 위치는 `BlockRowService::VALID_POSITIONS` = `topbar, index, left, right, subhead, subfoot, contenthead, contentfoot` (`src/Service/Block/BlockRowService.php`). 메인화면 전용 행을 구분하는 특별 값 `POSITION_MENU_MAIN_ONLY`도 같은 서비스가 재노출한다.
- **BlockColumn** — 행 안의 칸. `content_type`(무엇을), `content_skin`(어떻게), `content_config`(설정), `content_items`(어떤 항목을)를 가진다. 검증·새니타이즈는 `src/Service/Block/BlockColumnService.php`와 `BlockContentSanitizer`가 담당한다.
- **콘텐츠 타입** — 칸에 들어갈 출력 종류. Core 기본 6종(`html`, `image`, `movie`, `outlogin`, `menu`, `include` — `BlockRegistry::initializeCoreTypes()`)에 Plugin/Package가 자기 타입을 더한다.
  - `html` 타입은 html/css/js **채널 분리**가 원칙이다(정화기가 html 안의 style/script 를 제거). css/js 채널은 프론트에서 **자기 칸으로 자동 스코핑**된다 — CSS 는 `BlockCssScoper` 가 셀렉터를 `#bc-{columnId}` 하위로 프리픽스(@media 재귀, @keyframes 원문 유지)하고, JS 는 컨테이너 요소를 `block` 인자로 받는 IIFE 로 래핑된다(`HtmlRenderer`). 격리가 아니라 실수 방지다 — window 접근은 가능하며, 에디터 미리보기와 프론트가 같은 의미론을 가진다(WYSIWYG).
  - `menu` 타입은 `content_config` 의 `scope`(all/current — 현재 위치 하위만)·`orientation`·`depth` 와 아이템 선택(`content_items` — 선택한 1차 메뉴만 노출)을 지원하며 관리자 행 폼에 설정 UI 가 있다.
- **스킨** — 콘텐츠 타입의 출력 템플릿. 같은 타입도 스킨을 바꿔 다른 형태로 출력한다.
- **블록 에디터·블록킷** — 각각 미리보기 기반 편집 화면과 구성 이식 도구. 이 장 후반에서 다룬다.

### 렌더 흐름

프론트(`IndexController.index`, `PageController.view`)가 행 목록을 읽으면, `src/Service/Block/BlockRenderService.php`가 행 단위 2단계 캐시(행 ID 목록 캐시 + 행 렌더 결과 캐시)를 거쳐 각 칸을 렌더한다. 칸 하나의 렌더는 `BlockRegistry::createRenderer($contentType)`으로 렌더러 인스턴스를 만들어 `render(BlockColumnView $column): string`을 호출하는 것이 전부다. 렌더러가 받는 것은 칸 엔티티가 아니라 읽기 전용 뷰(`Mublo\Contract\Block\BlockColumnView`)다. `noCache` 옵션이 켜진 타입(로그인 위젯 등)은 캐시를 건너뛴다. 캐시 키와 무효화 API는 `docs/dev-guide/block-system.md`의 "캐싱" 절에 정리돼 있다.

코어의 책임은 여기까지다 — 행/칸의 저장·배치·캐시·렌더러 호출. 아이템이 무엇을 의미하는지(게시판, 배너, 상품)는 코어가 알지 못하며, 그 의미는 전적으로 확장의 렌더러와 이벤트 구독자가 소유한다.

## 개입 4단계

### 1단계 · 스킨 추가 — 출력만 교체

가장 가벼운 개입. 코드를 한 줄도 등록하지 않고, 기존 콘텐츠 타입의 출력 템플릿만 추가한다.

`src/Service/Block/BlockSkinService.php`가 스킨을 발견하는 규약은 디렉토리 구조다.

```text
{스킨 베이스}/{contentType}/{skinName}/
├── {skinName}.php   # 필수 — 이 파일이 존재해야 유효한 스킨 (isValidSkin)
├── style.css        # 선택 — 있으면 자동 포함
└── script.js        # 선택 — 있으면 자동 포함
```

스킨 베이스는 두 갈래다. 타입이 `BlockRegistry`에 `skinBasePath` 옵션을 등록했으면 그 절대 경로에서, 아니면 코어 기본 경로 `views/Block/{type}/`에서 스캔한다(`BlockSkinService::getSkinList()`). 기본 스킨명은 `basic`이고, `html`·`include` 타입은 스킨을 쓰지 않는다(`NO_SKIN_TYPES`).

실제 Board의 `boardgroup` 타입에는 `packages/Board/views/Block/boardgroup/basic-tab/basic-tab.php` 스킨이 있고, `skinBasePath` 아래 디렉토리 스캔으로 관리자 선택 목록에 나타난다. 같은 구조로 스킨 디렉토리와 동일한 이름의 PHP 파일을 추가한다. 스킨 파일에 전달되는 변수 목록과 제목 파셜 규약은 `docs/dev-guide/block-system.md`의 SkinRendererTrait 절을 본다.

### 2단계 · 블록 편집기에 아이템 공급/필터 — 이벤트 구독

타입은 이미 있고, 그 타입이 다룰 **항목 목록**을 공급하거나 렌더 직전에 걸러내고 싶을 때. 클래스 등록 없이 이벤트 구독만으로 개입한다.

**아이템 공급 — `BlockContentItemsCollectEvent`** (`src/Core/Event/Block/BlockContentItemsCollectEvent.php`). 관리자 블록 폼에서 콘텐츠 타입을 선택하면 코어 `BlockRowController`가 이 이벤트를 발행하고, 구독자가 `setItems([['id' => ..., 'label' => ...], ...])`로 응답한다. Board의 실제 구현(`packages/Board/Subscriber/BlockContentItemsSubscriber.php`):

```php
public function onCollect(BlockContentItemsCollectEvent $event): void
{
    match ($event->getContentType()) {
        'board'        => $this->collectBoards($event),
        'boardcomment' => $this->collectBoards($event),
        'boardgroup'   => $this->collectGroups($event),
        default        => null,
    };
}

private function collectBoards(BlockContentItemsCollectEvent $event): void
{
    $boards = $this->boardConfigRepo->findActiveByDomain($event->getDomainId());
    $event->setItems(array_map(fn($b) => [
        'id'    => $b->getBoardSlug(),
        'label' => $b->getBoardName(),
    ], $boards));
}
```

`getDomainId()`로 도메인을 좁히는 것에 주목한다 — 아이템 공급도 도메인 경계 안에서 이뤄진다.

**렌더 전 필터 — `BlockContentFilterEvent`** (`src/Core/Event/Block/BlockContentFilterEvent.php`). 렌더러가 아이템을 resolve한 후 스킨 렌더링 전에 발행한다. 다른 Package가 문맥(브랜드샵, 카테고리 등)에 따라 아이템을 걸러낼 수 있다. `filterItems(fn($item) => bool)` 편의 메서드를 제공한다. 발행 측 실례는 `plugins/Banner/Block/BannerRenderer.php`다.

```php
$items = $this->resolveItems($contentItems);

// 패키지 필터링 이벤트 발행
if ($this->eventDispatcher && !empty($items)) {
    $filterEvent = new BlockContentFilterEvent('banner', $items);
    $this->eventDispatcher->dispatch($filterEvent);
    $items = $filterEvent->getItems();
}
```

이 이벤트는 렌더러가 발행해야 동작한다. 새 타입을 만들 때(4단계) 다른 확장의 필터 개입 가능성을 열어두려면 같은 패턴을 렌더러에 넣는다.

### 3단계 · 관리자 선택 목록(DualListbox) 아이템 공급 — 인터페이스 구현

2단계의 이벤트 방식 대신, 타입 등록에 Provider 클래스를 직접 묶는 방식이다. `src/Core/Block/BlockItemsProviderInterface.php`는 메서드 하나다.

```php
interface BlockItemsProviderInterface
{
    /** @return array<int, array{id: string, label: string}> */
    public function getItems(int $domainId): array;
}
```

구현 클래스를 `registerContentType`의 옵션으로 등록한다.

```php
BlockRegistry::registerContentType(
    type: 'banner',
    kind: BlockContentKind::PLUGIN->value,
    title: '배너',
    rendererClass: BannerRenderer::class,
    options: [
        'hasItems'      => true,                       // DualListbox 선택 UI 표시
        'itemsProvider' => BannerItemsProvider::class, // 목록 공급자
    ]
);
```

`BlockRowController`가 콘텐츠 타입 변경 시 `BlockRegistry::getItemsProviderClass($type)`로 Provider를 찾아 목록을 조회한다. 자기 타입의 목록은 3단계(등록에 묶임)로, 남의 타입에 대한 개입은 2단계(이벤트)로 — 두 방식은 이렇게 역할이 갈린다.

### 4단계 · 새 콘텐츠 타입 등록 — 가장 깊은 개입

자기만의 출력 종류를 만든다. 등록 지점은 Provider의 `boot()`이며, 레지스트리는 `src/Core/Block/BlockRegistry.php`다. Board의 실제 등록(`packages/Board/BoardProvider.php` boot(), 3건 중 발췌):

```php
BlockRegistry::registerContentType(
    type: 'board',
    kind: BlockContentKind::PACKAGE->value,
    title: '게시판 최신글',
    rendererClass: BoardRenderer::class,
    options: [
        'hasItems'     => true,
        'hasStyle'     => true,
        'skinBasePath' => MUBLO_PACKAGE_PATH . '/Board/views/Block/',
    ]
);
// 같은 방식으로 'boardcomment'(최신댓글), 'boardgroup'(게시판 그룹)도 등록
```

등록 규칙 (`BlockRegistry::registerContentType()` 구현 기준):

- **`rendererClass` 필수** — `src/Core/Block/Renderer/RendererInterface.php`(`render(BlockColumnView): string`)를 구현해야 한다. 클래스가 없거나 인터페이스를 구현하지 않으면 등록 시점에 `InvalidArgumentException`이다(오타가 운영 화면의 빈 블록으로 늦게 드러나지 않도록). `createRenderer()` 시점에 재검증한다.
- **`configFormClass` 호환 필드** — `BlockRegistry`는 기존 등록값을 보관하고 검증하지만 `src/Core/Block/Form/ConfigFormInterface.php`는 `@deprecated`다. 현재 관리자 블록 설정 UI는 JS(`adminScript`) 기반이며 PHP 서버사이드 폼 렌더링 경로는 사용되지 않는다. 신규 콘텐츠 타입은 `adminScript`/`adminScriptInit`을 사용한다.
- **`kind`** — `BlockContentKind` Enum의 CORE/PLUGIN/PACKAGE. 관리자 select에서 그룹핑에 쓰인다(`getContentTypesGroupedByKind()`).
- **타입 코드 선점은 선착순** — 코어는 확장에 타입 코드 네이밍을 강제하지 않으므로 충돌은 언제든 일어난다. 이미 등록된 `type`을 다시 등록하면 **예외가 아니라 늦게 온 등록 하나만 생략**되고 충돌이 기록된다(`recordTypeConflict()` → 로그 + `ExtensionLoadDiagnostics` 합류). 확장 전체를 중단시키지 않는 대신, 진 쪽이 왜 사라졌는지는 확장 진단에서만 확인할 수 있다. 의도적 교체는 `options: ['allowOverwrite' => true]`로만 한다. 코어 기본 타입은 지연 초기화(`ensureInitialized()`)로 항상 먼저 자리를 잡으므로 확장이 선점할 수 없다.
- 주요 옵션: `hasItems`(아이템 선택 UI), `hasStyle`(표시 스타일), `skinBasePath`(1단계의 스킨 스캔 경로), `itemsProvider`(3단계), `adminScript`/`adminScriptInit`(관리자 커스텀 설정 JS), `noCache`(사용자 상태 의존 콘텐츠). 전체 표는 `docs/dev-guide/block-system.md`.

렌더러가 스킨에서 CSS/JS를 등록하려면 컨테이너 등록 시 `AssetManager`를 주입한다 — Board는 `BoardRenderer` 등 3개 렌더러 모두 `$renderer->assetManager = $c->get(AssetManager::class)` 패턴을 쓴다(`BoardProvider::register()`).

4단계를 택하면 나머지 단계가 자기 책임이 된다: 스킨 규약(1단계)을 따르고, 아이템 공급(2·3단계)을 붙이고, 필터 이벤트를 발행할지 결정한다.

## 블록 페이지와 라이프사이클 이벤트

`BlockPageService`는 페이지 저장·삭제 후 이벤트를 발행한다(`src/Service/Block/BlockPageService.php`).

| 이벤트 | 발행 시점 | 대표 용도 |
|---|---|---|
| `BlockPageCreatedEvent` | DB 저장 후 (readonly) | 프론트 메뉴 아이템 자동 등록 |
| `BlockPageUpdatedEvent` | 페이지 코드·제목 변경 후 (readonly) | 자동 메뉴 아이템 동기화(옛 코드 → 새 코드) |
| `BlockPageDeletedEvent` | DB 삭제 후 (readonly) | 프론트 메뉴 아이템 자동 삭제 |
| `BlockPageMenuSyncEvent` | 대체 생성 경로 | 위 세 이벤트를 타지 않는 경로에서 메뉴 동기화를 명시 요청 |
| `BlockPageRenderingEvent` | `/p/{code}` 렌더 시 (`PageController.view`) | Package가 페이지에 추가 HTML 주입 — `addHtml($html, $order)` |

`BlockPageRenderingEvent`는 `getPage()->getPageConfig()`로 페이지 설정을 읽어 조건부로 HTML을 붙이는 패턴을 상정한다(소스 주석의 예: 회사 정보 푸터). 페이지 코드에는 예약어가 있다(`BlockPageService::RESERVED_CODES` — `admin`, `api`, `board`, `p` 등).

## 블록킷 — 구성의 이식

블록 구성(행/칸/페이지)을 portable JSON으로 내보내고 다른 사이트에 적용하는 도구다.

- **내보내기** — `src/Service/Block/BlockKitExporter.php`. position 단위(`exportPosition`), 메인화면 복합 단위(`exportMainScreen`) 또는 페이지 단위(`exportPage`)로, ID·도메인·타임스탬프 등 설치 종속 필드를 제거(`*_OMIT_FIELDS`)한 킷을 만든다. 메인화면 킷은 `screen/main` 대상으로 슬롯별 position과 렌더링에 필요한 `site_config` 레이아웃 키를 함께 보존한다. 포맷은 `mublo-starter-kit` v1.0.
- **적용** — `src/Service/Block/BlockKitApplier.php`. dry-run(검증)과 실제 반영을 담당한다. 킷은 제3자 파일이므로 행·칸·페이지·site_config 필드를 전부 화이트리스트로 받고, 크기 상한 2 MiB, 적용 모드는 `append`/`replace` 두 가지다. 메인화면 킷은 슬롯별 전역 행을 유지하며, 화면 재현에 필요한 레이아웃 설정을 선택 해제할 수 없이 같은 트랜잭션에서 `domain_config.site_config`에 병합한다. 등록되지 않은 `content_type`은 `BlockRegistry::hasContentType()`으로 판정해 걸러낸다.

### 이 설치본에 없는 것을 만났을 때

킷은 남의 설치본에서 만들어진 파일이라, 이쪽에 없는 것을 지목할 수 있다. 결손의 종류에 따라 처리가 다르다.

| 결손 | 처리 | 운영자가 보는 것 |
|---|---|---|
| 미등록 `content_type` | 경고 후 구조만 보존 | "확장을 설치하면 나타납니다" + `needs_setup` 항목 |
| 없는 `content_skin` | **기본 스킨(`basic`)으로 대체**하고 통과 | "스킨 'X' 가 이 사이트에 없어 'basic' 으로 적용합니다" 경고 |
| 기본 스킨조차 없는 타입 | 오류 — 적용 차단 | "존재하지 않는 블록 스킨입니다" |

스킨 대체가 안전한 이유는 한 콘텐츠 타입의 모든 스킨이 `SkinRendererTrait`가 주는 **같은 표준 변수**를 받기 때문이다. 스킨을 바꿔도 데이터 계약이 깨지지 않는다. 대체 값은 DB에 `basic`으로 저장된다 — 원본 스킨명을 남기면 "DB에는 존재하지 않는 스킨이 없다"는 불변식이 깨지고, 그 행을 나중에 관리자 폼에서 저장할 때 같은 검증에 막혀 저장 불가 상태가 된다.

이 완화는 **킷 가져오기 경로에만** 적용된다(`BlockColumnWriteContext::SOURCE_KIT_IMPORT`). 관리자 블록 폼의 스킨 값은 실재 스킨 드롭다운에서 오므로, 없는 스킨이 오면 화면 버그이거나 조작된 요청이고 조용히 고칠 일이 아니다 — 그쪽은 오류를 유지한다.

**전제: 블록킷은 사이트 구축 단계용 도구다.** 운영자가 사이트를 세팅할 때 시작 구성을 가져오는 용도이며, 방문자에게 노출되는 런타임 기능이 아니다. 킷이 실행 코드를 담을 수는 없다 — 새 `content_type`은 4단계처럼 PHP 클래스 배포로만 생기고, 킷은 이미 등록된 블록을 조립할 뿐이다. 확장 제작자가 킷 친화적으로 만들기 위한 권고(자연키 참조, 표시 스냅샷 금지, 빈 상태 처리 등)는 `docs/dev-guide/block-system.md`의 "블록 킷 호환 지침"에 있다.

## 블록 에디터

`src/Controller/Admin/BlockEditorController.php`. 실제 프론트를 iframe으로 띄우고 렌더 마커(`.block-section--{rowId}`, `#bc-{columnId}`)로 클릭을 행/칸에 매핑하는 미리보기 기반 편집 화면이다. 컨텍스트·행 메타·행 편집 데이터 조회는 이 컨트롤러가 담당하고, 행·칸 구성 저장은 기존 블록 행/페이지 컨트롤러 API를 재사용한다. 이 컨트롤러 자체에도 프레임 스킨 변경, HTML 블록 AI 생성, AI 자료 업로드·삭제·이미지 편집 API가 있다. `aiHtml()`은 생성 결과를 반환하며 블록 저장은 기존 행 저장 경로가 담당한다. 화면 전체를 바꿀 수 있는 도구이므로 권한은 블록킷과 같은 급 — 도메인 운영자 이상이다. 내부 구현(JS 편집기 등)은 공개 규약이 아니므로 여기서는 개요만 둔다. HTML 블록의 AI 편집은 [27. AI 시스템](27-ai.md)에서 다룬다.

## Best Practice / 경계

- **렌더러는 도메인을 격리한다.** 렌더러가 받는 것은 `BlockColumn` 하나이며, 데이터 조회는 `$column->getDomainId()` 기준으로 한다(`packages/Board/Block/BoardRenderer.php`). 아이템 공급 구독자도 `$event->getDomainId()`로 좁힌다. 다른 도메인 데이터가 블록으로 새는 것은 멀티 도메인 모델([09. 멀티 도메인](09-multi-domain.md))의 위반이다.
- **사용자 상태 의존 콘텐츠는 `noCache`.** 로그인 위젯처럼 보는 사람마다 다른 출력을 캐시하면 안 된다(`outlogin` 타입의 실제 등록이 그 예다).
- **공개 규약 vs 내부 구현.** 확장이 기대도 되는 표면은 `BlockRegistry`, `RendererInterface`, `BlockItemsProviderInterface`, 블록 이벤트 7종, 스킨 디렉토리 규약이다. `ConfigFormInterface`는 다음 major 제거 예정인 deprecated 표면이므로 신규 확장이 사용하면 안 된다. `BlockRenderService`의 캐시 키 형식, 블록 컨트롤러 내부, 에디터 JS는 내부 구현이며 예고 없이 바뀔 수 있다. 판정 기준은 `docs/compatibility-policy.md`다([15. Public API](15-public-api.md)).
- **용어 주의.** 이 장의 블록 콘텐츠 타입은 [22장의 관리자 대시보드 위젯](22-admin-dashboard-widgets.md)과 다른 것이고, "프론트 위젯 플러그인"과도 다른 것이다. 코어의 `outlogin` 타입 표시명이 "로그인 위젯"이지만 이는 블록 콘텐츠 타입의 하나일 뿐이다.

## 관련 문서

- [08. Event](08-event.md) — 이벤트 구독·발행 규약과 안정성 분류
- `docs/dev-guide/block-system.md`(블록 시스템 개발) — SkinRendererTrait, 캐싱, options 전체 표, 블록킷 호환 지침
- `docs/block-editor-overview.md`(블록 편집 개요) — 운영자/개발자 관점 요약
- `docs/user-guide/block-page-builder.md`(블록 페이지 빌더) — 운영자용 사용 가이드
- [29. Package Guide](29-package-guide.md) · [30. Plugin Guide](30-plugin-guide.md) — Provider `boot()` 등록의 전체 맥락
