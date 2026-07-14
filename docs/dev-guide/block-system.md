# 블록 시스템 개발

## 구조

블록 시스템은 3단계 계층으로 페이지를 구성합니다.

```
BlockPage (페이지)
  └── BlockRow (행)
        └── BlockColumn (열)
              └── 콘텐츠 타입 (HTML, 배너, 게시판 최신글 등)
```

- **BlockPage** — 하나의 페이지. 레이아웃(전체/좌측/우측/양측 사이드바), SEO 설정, 접근 레벨
- **BlockRow** — 가로 행. 너비(와이드/콘테이너), 배경색/이미지, 패딩, 정렬
- **BlockColumn** — 행 안의 열. 너비 비율, 콘텐츠 타입, 스킨, 설정

관련 문서:
- [이벤트 시스템](event-system.md)
- [패키지 만들기](package-development.md)
- [플러그인 만들기](plugin-development.md)

## BlockRegistry

`src/Core/Block/BlockRegistry.php`

Plugin/Package가 자신의 콘텐츠 타입을 등록하는 정적 레지스트리입니다.

블록 시스템은 두 가지 확장 축을 함께 사용한다.
- 콘텐츠 타입 자체를 등록할 때: `BlockRegistry`
- 블록 페이지/아이템 수집 흐름에 반응할 때: 코어 이벤트

### 콘텐츠 타입 등록

```php
BlockRegistry::registerContentType(
    type: 'board',                              // 타입 코드 (고유)
    kind: BlockContentKind::PACKAGE->value,     // 'core', 'plugin', 'package'
    title: '게시판 최신글',                       // 관리자 표시명
    rendererClass: BoardRenderer::class,        // RendererInterface 구현 클래스
    configFormClass: BoardConfigForm::class,     // 설정 폼 클래스 (선택)
    options: [
        'hasItems'      => true,                // 항목 선택 UI (DualListbox)
        'hasStyle'       => true,                // 디스플레이 스타일 옵션 (list/slide)
        'skinBasePath'   => MUBLO_PACKAGE_PATH . '/Board/views/Block/',
        'itemsProvider'  => BoardItemsProvider::class,  // 항목 목록 제공자
        'adminScript'    => '/serve/plugin/Banner/assets/js/block-banner.js',  // 관리자 커스텀 JS
        'adminScriptInit' => 'MubloBlockBanner', // JS 전역 객체명
        'noCache'        => true,                // 캐시 비활성화 (로그인 위젯 등)
    ]
);
```

### options 정리

| 옵션 | 타입 | 설명 |
|------|------|------|
| `hasItems` | bool | 항목 선택 UI 표시 (게시판/배너 선택 등) |
| `itemsProvider` | string | `BlockItemsProviderInterface` FQCN |
| `hasStyle` | bool | 디스플레이 스타일 옵션 (list/slide 모드) |
| `skinBasePath` | string | 스킨 디렉토리 절대 경로 |
| `adminScript` | string | 관리자 블록 설정 UI용 JS 파일 |
| `adminScriptInit` | string | JS 전역 초기화 객체명 |
| `noCache` | bool | 사용자별 달라지는 콘텐츠 (캐시 안 함) |
| `allowOverwrite` | bool | 기존 등록 덮어쓰기 허용 |

### 조회 메서드

```php
BlockRegistry::hasContentType('board');           // bool
BlockRegistry::getContentType('board');            // ?array (전체 정보)
BlockRegistry::getContentTypes('package');          // array (kind별 필터)
BlockRegistry::getContentTypesGroupedByKind();      // array (kind별 그룹)
BlockRegistry::createRenderer('board');             // ?RendererInterface (인스턴스 생성)
BlockRegistry::getSkinBasePath('board');             // ?string
BlockRegistry::isNoCache('outlogin');               // bool
BlockRegistry::hasItems('board');                   // bool
```

## RendererInterface

`src/Core/Block/Renderer/RendererInterface.php`

모든 블록 렌더러가 구현해야 하는 인터페이스입니다.

```php
interface RendererInterface
{
    public function render(BlockColumn $column): string;
}
```

`BlockColumn` 엔티티에서 설정(`getContentConfig()`), 항목(`getContentItems()`), 스킨(`getContentSkin()`) 등을 꺼내 HTML을 반환합니다.

### 콘텐츠 스택과 다중 렌더 호출

운영자는 한 칸에 여러 콘텐츠를 세로로 배치할 수 있다(콘텐츠 스택). 이때
코어는 각 콘텐츠마다 칸 레이아웃 + 콘텐츠 필드를 합친 렌더 전용
`BlockColumn` view 를 만들어 **같은 렌더러를 여러 번 호출**한다. 렌더러
계약은 그대로지만 다음 규약을 지켜야 한다.

- **같은 렌더러 인스턴스가 한 요청에서 여러 번 호출될 수 있다** — render
  상태를 객체 프로퍼티에 누적하지 말 것.
- **DOM ID 는 `getColumnId()` 대신 `getRenderKey()` 로 만들 것.**
  렌더 키는 단일 칸에서 `"12"`(column_id 문자열 그대로 — 기존 DOM ID
  불변), 스택 콘텐츠에서 `"12-c-31"` 이다. 접두사는 렌더러가 소유한다:
  `'block_faq_' . $column->getRenderKey()`. 같은 타입을 한 스택에 반복
  배치해도 ID 가 충돌하지 않는다.
- HTML 콘텐츠의 CSS/JS 자동 스코핑 앵커도 `#bc-{renderKey}` 다 — 같은
  스택의 HTML 콘텐츠끼리 격리된다.
- `getColumnId()` 를 계속 쓰는 기존 확장은 단일 칸에서 영향이 없다.
  스택 반복 배치 시에만 ID 가 중복될 수 있으므로 전환을 권고한다.

## SkinRendererTrait

`src/Core/Block/Renderer/SkinRendererTrait.php`

대부분의 렌더러가 사용하는 트레이트로, 스킨 파일 기반 렌더링을 제공합니다.

### 구현 필수 메서드

```php
protected function getSkinType(): string;      // 예: 'board', 'banner'
protected function getSkinBasePath(): string;   // 스킨 디렉토리 경로
```

### 사용 예

```php
class BannerRenderer implements RendererInterface
{
    use SkinRendererTrait;

    public function __construct(private BannerService $bannerService) {}

    protected function getSkinType(): string { return 'banner'; }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Banner/views/Block/';
    }

    public function render(BlockColumn $column): string
    {
        $items = $this->resolveItems($column->getContentItems() ?? []);
        $skin = $column->getContentSkin() ?: 'basic';

        return $this->renderSkin($column, $skin, [
            'items' => $items,
            'config' => $column->getContentConfig() ?? [],
        ]);
    }
}
```

### 스킨 파일 경로

```
{skinBasePath}/{skinType}/{skin}/{skin}.php
```

예: `plugins/Banner/views/Block/banner/basic/basic.php`

### 스킨에 전달되는 변수

```php
$mublo          // 공통 Front 스킨 데이터 계약
$column         // BlockColumn 엔티티
$titleConfig    // 제목 설정 배열
$titlePartial   // 제목 파셜 파일 경로
$contentConfig  // 콘텐츠 설정
$skinDir        // 현재 스킨 디렉토리
$assets         // ?AssetManager (CSS/JS 등록용)
// + renderSkin()의 3번째 인자로 전달한 데이터
```

`$mublo`는 프레임·콘텐츠 스킨과 같은 구조다. 캐시 가능한 블록에서는 방문자 정보가 섞이지 않도록 `viewer.available`과 `request.available`이 `false`다. 방문자별 출력이 필요한 타입은 등록 시 `options: ['noCache' => true]`를 반드시 지정한다. 전체 구조는 [Front 스킨 데이터 계약](../reference/front-view-data-contract.md)을 따른다.

스킨에서 DB, 세션, DI 컨테이너를 직접 꺼내지 않는다. 필요한 표시 데이터는 렌더러가 `renderSkin()`의 세 번째 인자로 명시적으로 전달하고, 사이트·회원·메뉴 같은 공통 정보는 `$mublo`에서 읽는다.

### 출력 이스케이프 — 변수는 항상 `e()`로

스킨은 순수 PHP라 자동 이스케이프가 없다. **DB나 사용자 입력에서 온 값을 HTML 로 출력할 때는 항상 전역 헬퍼 `e()`를 거친다.** 이것이 스킨의 XSS 방어 규칙 전부다.

```php
<h3><?= e($item['title']) ?></h3>
<a href="/goods/<?= (int) $item['id'] ?>" title="<?= e($item['name']) ?>">
    <?= e($item['name']) ?>
</a>
```

- `e()`는 어디서나 사용 가능하며 별도 선언이 필요 없다 (`src/Helper/EnvHelpers.php`).
- `null` 은 빈 문자열이 되므로 `e($row['title'] ?? null)` 처럼 안심하고 쓸 수 있다. 숫자도 그대로 받는다.
- 관리자가 에디터로 작성한 HTML 콘텐츠처럼 **태그를 살려서 출력해야 하는 값**은 `e()` 대신 저장 시점에 `HtmlSanitizer` 정화를 거친 값을 그대로 출력한다. 이 두 경우 외에 "그냥 `echo`" 는 없다.

### 제목 파셜

스킨 디렉토리에 `title.php`가 있으면 우선 사용, 없으면 공유 파셜 사용:

1. `{skinBasePath}{skinType}/{skin}/title.php` (스킨별 오버라이드)
2. `views/Block/_shared/title.php` (공유 기본)

## 스킨 디렉토리 구조

```
views/Block/                          # Core 스킨
├── _shared/
│   └── title.php                     # 공유 제목 파셜
├── html/basic/basic.php
├── image/basic/basic.php
├── menu/basic/basic.php
└── outlogin/basic/basic.php

plugins/Banner/views/Block/           # Plugin 스킨
└── banner/
    └── basic/
        ├── basic.php                 # 메인 스킨 파일
        └── style.css                 # 스킨 CSS (선택)

packages/Board/views/Block/           # Package 스킨
├── board/
│   └── basic/basic.php
└── boardgroup/
    ├── basic/basic.php
    └── tab/tab.php                   # 추가 스킨
```

## 캐싱

`src/Service/Block/BlockRenderService.php`

2단계 캐싱 전략:

1. **행 목록 캐시** — 위치/페이지별 행 ID 목록
2. **행 콘텐츠 캐시** — 행별 렌더링된 HTML + 에셋 경로

### 캐시 키

```
block:ids:pos:{domainId}:{position}    # 위치별 행 ID 목록
block:ids:page:{pageId}                # 페이지별 행 ID 목록
block:row:{rowId}                      # 행 렌더링 결과
```

### 캐시 무효화

```php
// 단일 행 콘텐츠
$blockRenderService->invalidateRowContentCache($rowId);

// 행 + 관련 목록 캐시
$blockRenderService->invalidateRowRelatedCache($row);

// 도메인 전체
$blockRenderService->invalidateDomainCache($domainId);
```

`noCache: true`인 콘텐츠 타입(아웃로그인 등)은 캐싱을 건너뜁니다.

## Provider에서 등록

### Package (Board) 예시

```php
public function boot(DependencyContainer $container, Context $context): void
{
    BlockRegistry::registerContentType(
        type: 'board',
        kind: BlockContentKind::PACKAGE->value,
        title: '게시판 최신글',
        rendererClass: BoardRenderer::class,
        options: [
            'hasItems' => true,
            'hasStyle' => true,
            'skinBasePath' => MUBLO_PACKAGE_PATH . '/Board/views/Block/',
        ]
    );
}
```

### Plugin (Banner) 예시

```php
public function boot(DependencyContainer $container, Context $context): void
{
    BlockRegistry::registerContentType(
        type: 'banner',
        kind: BlockContentKind::PLUGIN->value,
        title: '배너',
        rendererClass: BannerRenderer::class,
        configFormClass: BannerConfigForm::class,
        options: [
            'hasItems' => true,
            'hasStyle' => true,
            'skinBasePath' => MUBLO_PLUGIN_PATH . '/Banner/views/Block',
            'adminScript' => '/serve/plugin/Banner/assets/js/block-banner.js',
            'adminScriptInit' => 'MubloBlockBanner',
        ]
    );
}
```

> Renderer를 DI 컨테이너에 등록할 때 `AssetManager`를 주입해야 스킨에서 CSS/JS를 등록할 수 있습니다:

```php
$container->singleton(BannerRenderer::class, function ($c) {
    $renderer = new BannerRenderer($c->get(BannerService::class));
    $renderer->assetManager = $c->get(AssetManager::class);
    return $renderer;
});
```

## 블록 관련 코어 이벤트

블록 시스템에서 자주 쓰는 코어 이벤트:

| 이벤트 | 용도 |
|------|------|
| `BlockPageCreatedEvent` | 블록 페이지 생성 후 후처리 |
| `BlockPageDeletedEvent` | 블록 페이지 삭제 후 후처리 |
| `BlockContentItemsCollectEvent` | 콘텐츠 타입별 선택 항목 공급 |
| `BlockPageRenderingEvent` | 블록 페이지 렌더링 시 추가 HTML 주입 |

예시:
- 메뉴 자동 등록/삭제
- 패키지별 콘텐츠 후보 목록 공급
- 블록 페이지 하단 부가 정보 출력

이벤트 발행 지점과 안정성 분류는 [이벤트 시스템](event-system.md)을 기준으로 본다.

---

## 블록 킷 호환 지침

블록 구성은 JSON 블록 킷으로 내보내 다른 사이트에 적용할 수 있다. 새 블록 타입을 등록하는 확장은 다음을 지키면 블록 킷과 매끄럽게 동작한다. **코어 계약이 아니라 권고 사항이며, 지키지 않아도 블록 킷은 폴백으로 동작한다.**

### 렌더러 네임스페이스 관례를 따를 것

블록 킷 내보내기가 renderer FQCN을 파싱해 `requires.block_types[].provider` 초안을 뽑는다.

- `Mublo\Plugin\{Name}\...` → `{Name}`
- `Mublo\Packages\{Name}\...` → `{Name}`

관례를 벗어나면 저작자가 내보내기 폼에서 손으로 고쳐야 한다. `provider`는 안내 문구용이고, 적용 가능 여부는 `BlockRegistry::hasContentType()`이 판단하므로 틀려도 안전하다.

### `content_config`에 ID·코드를 저장한다면 문서화할 것

코어는 참조가 `content_items` 밖에도 있다는 사실을 알 수 없다. 예를 들어 `product_auto`는 `content_config.category_code`에 도메인 종속 참조를 담는다. 문서화하지 않으면 블록 킷이 그 참조를 검사·정리하지 못한다.

### 블록 킷에 담을 참조는 자연키여야 한다

코드·슬러그(`notice`, `menu_code`)는 대상 사이트에서 맞아떨어질 확률이 높다. 숫자 PK(`banner_id`, `goods_id`)는 다른 설치에서 엉뚱한 데이터를 가리킨다. **참조를 담을 수 없으면 비우는 것이 정상이다** — 블록 킷은 구조를 주고, 콘텐츠는 사용자의 것이다.

### 데이터가 없을 때 자체 빈 상태를 반환할 것

렌더러가 빈 문자열을 반환해도 칸은 사라지지 않는다. `buildColumnHtml()`이 코어 backstop 플레이스홀더(`.block-placeholder`, *"표시할 콘텐츠가 없습니다."*)로 칸 영역을 잡아 주기 때문이다.

다만 그것은 **코어 문구·코어 톤**이다. 스킨에 어울리는 안내를 내보내려면 직접 반환한다.

```php
// 코어 backstop 이 뜬다 — 동작은 안전하지만 스킨 톤이 아니다
if (empty($items)) {
    return '';
}

// 스킨이 소유하는 빈 상태 (.block-empty 글로벌 스타일 적용)
if (empty($items)) {
    return $this->renderEmptyContent('등록된 콘텐츠가 없습니다.');
}
```

### 표시 스냅샷을 `content_items`에 저장하지 말 것

`BannerRenderer`처럼 이미지 URL 같은 표시 값을 `content_items`에 스냅샷해 두고 DB를 재조회하지 않으면, 블록 킷을 다른 사이트에 적용했을 때 **빈 출력이 아니라 깨진 이미지**가 뜬다. 빈 출력보다 나쁘다. `id`만 저장하고 렌더 시점에 조회하는 편이 이식성이 좋다.

### 복제/백업 모드까지 지원하려면 `BlockItemsPortableInterface`를 구현할 것

숫자 PK ↔ 자연키 변환 훅이다(후순위 기능). 구현하지 않은 확장은 "후보 목록에 있으면 적용, 없으면 비움" 폴백을 탄다.

### 블록 킷은 블록을 조립하지, 블록을 정의하지 않는다

새 `content_type`을 만들려면 플러그인이나 패키지를 배포해야 한다. 렌더러는 `RendererInterface`를 구현한 PHP 클래스이며(`BlockRegistry`가 `class_exists()`와 상속을 검증), JSON으로 정의할 수 없다. 정의할 수 있게 만드는 것은 블록 킷이 실행 코드를 담는 것과 같다.

---

[< 이전: Contract 시스템](contract-system.md) | [다음: 패키지 만들기 >](package-development.md)
