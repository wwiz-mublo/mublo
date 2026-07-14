# 플러그인 만들기

## 플러그인이란?

Plugin은 Core나 Package에 **부가 기능을 추가**하는 확장입니다. 이벤트 구독, Contract 구현, 블록 콘텐츠 등록으로 기존 시스템에 기능을 끼워 넣습니다.

| 구분 | Plugin | Package |
|------|--------|---------|
| 역할 | 기존 기능 확장 | 독립 애플리케이션 |
| 예시 | 배너, FAQ, 소셜 로그인 | 게시판, 쇼핑몰 |
| 규모 | Service 1~3개 | 자체 Controller/Service/Repository 다수 |
| 핵심 연결 | 이벤트 구독 + Contract 구현 | 자체 라우트 + 이벤트 발행 |

먼저 읽을 문서:
- [이벤트 시스템](event-system.md)
- [Contract 시스템](contract-system.md)
- [Manifest 기준](manifest-standard.md)

플러그인에서 가장 자주 쓰는 코어 이벤트:
- `AdminMenuBuildingEvent`
- `LoginFormRenderingEvent`
- `RegisterFormRenderingEvent`
- `MemberFormRenderingEvent`
- `FrontFootRenderEvent`
- `DomainCreatedEvent`

이벤트 전수 목록과 안정성 분류는 [이벤트 시스템](event-system.md)에 정리돼 있다.

## 디렉토리 구조

```
plugins/MyPlugin/
├── MyPluginProvider.php         # 진입점 (필수)
├── manifest.json                # 메타데이터 (필수)
├── routes.php                   # URL 라우팅 (관리자 페이지용)
├── AdminMenuSubscriber.php      # 관리자 메뉴 등록
├── Controller/
│   └── MyPluginController.php   # 관리자 컨트롤러
├── Service/
│   └── MyPluginService.php
├── Repository/
│   └── MyPluginRepository.php
├── Subscriber/
│   └── SomeEventSubscriber.php  # 이벤트 구독자
├── Block/                       # 블록 렌더러 (선택)
│   ├── MyRenderer.php
│   ├── MyConfigForm.php
│   └── MyItemsProvider.php
├── views/
│   ├── Admin/
│   │   └── MyPlugin/
│   │       ├── Index.php
│   │       └── Form.php
│   └── Block/
│       └── mytype/
│           └── basic/
│               └── basic.php
└── database/
    └── migrations/
        └── 001_create_my_tables.sql
```

## manifest.json

manifest의 표준 키와 레거시 키 정리는 [Manifest 기준](manifest-standard.md)을 따른다.

```json
{
    "name": "MyPlugin",
    "label": "내 플러그인",
    "description": "플러그인 설명",
    "version": "1.0.0",
    "author": "개발자명",
    "author_url": "https://example.com",
    "icon": "bi-puzzle",
    "requires": {
        "core": ">=1.0.0"
    }
}
```

특수 옵션:
- `"hidden": true` — 확장 관리 화면에서 숨김 (인프라 플러그인)
- `category`는 `content`, `member`, `marketing`, `infrastructure`, `payment`, `messaging` 같은 공개 기준 분류를 권장
- `title`, `provider` 같은 레거시 키는 새 플러그인에 사용하지 않는다
- 운영자 플래그(`default`/`mandatory`/`super_only`)는 **개발 단계에서 설정하지
  않는다** — 배포의 운영 정책이므로 운영자가 소비한다. 역할은
  [Manifest 기준](manifest-standard.md)의 운영자 플래그 절 참조

## Provider 작성

```php
namespace Mublo\Plugin\MyPlugin;

use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Infrastructure\Database\Database;

class MyPluginProvider implements ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void
    {
        $container->singleton(MyPluginRepository::class, fn($c) =>
            new MyPluginRepository($c->get(Database::class))
        );

        $container->singleton(MyPluginService::class, fn($c) =>
            new MyPluginService($c->get(MyPluginRepository::class))
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 1. 관리자 메뉴 등록
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        // 2. 이벤트 구독 (비즈니스 로직)
        $eventDispatcher->addSubscriber(
            new SomeEventSubscriber($container->get(MyPluginService::class))
        );

        // 3. Contract 바인딩 (다른 Package에 데이터 제공 시)
        // $registry = $container->get(ContractRegistry::class);
        // $registry->bind(MyInterface::class, $container->get(MyPluginService::class));

        // 4. 블록 콘텐츠 타입 등록 (선택)
        // BlockRegistry::registerContentType(...);
    }
}
```

실무 기준:
- `register()`는 DI 등록만
- `boot()`는 이벤트 구독, Contract 바인딩, 블록 등록
- UI를 끼워 넣는 플러그인은 먼저 코어 안정 이벤트를 재사용하는지 확인한다

## 관리자 메뉴 등록

```php
namespace Mublo\Plugin\MyPlugin;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'MyPlugin';

    public static function getSubscribedEvents(): array
    {
        return [AdminMenuBuildingEvent::class => 'onAdminMenuBuilding'];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        // 방법 1: 독립 메뉴 추가
        $event->addPluginMenu('내 플러그인', 'bi-puzzle', [
            ['label' => '목록', 'url' => '/admin/myplugin/list', 'code' => '001'],
            ['label' => '설정', 'url' => '/admin/myplugin/settings', 'code' => '002'],
        ]);

        // 방법 2: 기존 Core 메뉴에 서브메뉴 추가
        // $event->addSubmenuTo('003', [  // '003' = 회원관리
        //     'label' => '내 기능',
        //     'url'   => '/admin/myplugin/feature',
        //     'code'  => '001',
        // ]);

        // 방법 3: 기존 서브메뉴 뒤에 삽입
        // $event->insertSubmenuAfter('003', '003_001', [
        //     'label' => '내 기능',
        //     'url'   => '/admin/myplugin/feature',
        //     'code'  => '002',
        // ]);
    }
}
```

### Code Prefix 규칙

Plugin 메뉴의 code는 자동으로 프리픽스됩니다:

| Source | Prefix | 예시 |
|--------|--------|------|
| Core | 없음 | `003` |
| Plugin | `P_{Name}_` | `P_MyPlugin_001` |
| Package | `K_{Name}_` | `K_Board_001` |

`AdminMenuBuildingEvent`는 코어 안정 이벤트다. 새 플러그인이 관리자 UI를 가지면 이 이벤트를 표준 진입점으로 사용한다.

## Contract 구현

다른 Package에 데이터를 제공할 때 Contract를 구현합니다.

판단 기준:
- “다른 곳에서 내 데이터를 읽어야 한다” → Contract
- “특정 시점에 내 기능이 반응해야 한다” → Event

### 1. 인터페이스 확인

`src/Contract/`에 이미 정의된 인터페이스를 구현하거나, 새 인터페이스를 추가합니다.

### 2. Service에서 구현

```php
namespace Mublo\Plugin\Faq\Service;

use Mublo\Contract\Faq\FaqQueryInterface;

class FaqService implements FaqQueryInterface
{
    public function __construct(private FaqRepository $faqRepository) {}

    public function getCategories(int $domainId): array
    {
        return $this->faqRepository->findCategoriesWithCount($domainId);
    }

    // ... 나머지 인터페이스 메서드 구현 ...

    // Contract 외 자체 CRUD도 같은 클래스에 작성
    public function createItem(int $domainId, array $data): Result { ... }
}
```

### 3. Provider에서 바인딩

```php
public function boot(DependencyContainer $container, Context $context): void
{
    $registry = $container->get(ContractRegistry::class);
    $registry->bind(
        FaqQueryInterface::class,
        $container->get(FaqService::class)
    );
}
```

1:N 등록 (결제 게이트웨이 등):

```php
$registry->register(
    PaymentGatewayInterface::class,
    'payapp',
    fn() => new PayAppGateway($config),
    ['label' => '페이앱']
);
```

## 블록 콘텐츠 등록

```php
// Provider.boot()에서

BlockRegistry::registerContentType(
    type: 'myplugin',
    kind: BlockContentKind::PLUGIN->value,
    title: '내 플러그인 블록',
    rendererClass: MyRenderer::class,
    configFormClass: MyConfigForm::class,
    options: [
        'skinBasePath' => MUBLO_PLUGIN_PATH . '/MyPlugin/views/Block',
        'hasItems' => true,
        'hasStyle' => true,
    ]
);
```

Renderer를 DI에 등록할 때 AssetManager 주입:

```php
// register()에서
$container->singleton(MyRenderer::class, function ($c) {
    $renderer = new MyRenderer($c->get(MyPluginService::class));
    $renderer->assetManager = $c->get(AssetManager::class);
    return $renderer;
});
```

## 에셋 경로

Plugin의 정적 파일(JS, CSS, 이미지)은 `plugins/` 디렉토리에 있으므로 직접 웹 접근이 안 됩니다. `ServeController`가 중계합니다:

```
/serve/plugin/{Name}/assets/{path}
```

예시:
- `/serve/plugin/Banner/assets/js/block-banner.js`
- `/serve/plugin/MyPlugin/assets/css/style.css`

뷰에서 사용:

```html
<link rel="stylesheet" href="/serve/plugin/MyPlugin/assets/css/style.css">
<script src="/serve/plugin/MyPlugin/assets/js/admin.js"></script>
```

## 설치/삭제 생명주기 (선택)

`InstallableExtensionInterface`를 구현하면 활성화/비활성화 시 로직을 실행할 수 있습니다.

```php
class MyPluginProvider implements ExtensionProviderInterface, InstallableExtensionInterface
{
    public function install(DependencyContainer $container, Context $context): void
    {
        // 최초 활성화: 마이그레이션 실행, 기본 데이터 시딩
        $runner = $container->get(MigrationRunner::class);
        $runner->run('plugin', 'MyPlugin', MUBLO_PLUGIN_PATH . '/MyPlugin/database/migrations');
    }

    public function uninstall(DependencyContainer $container, Context $context): void
    {
        // 비활성화: 메뉴 항목 제거 등 (DB 데이터는 보존)
    }
}
```

## 이벤트 선택 가이드

플러그인에서 자주 검토하는 코어 이벤트:

| 목적 | 권장 이벤트 |
|------|-------------|
| 관리자 메뉴 추가 | `AdminMenuBuildingEvent` |
| 로그인 UI 확장 | `LoginFormRenderingEvent` |
| 회원가입 폼 UI 확장 | `RegisterFormRenderingEvent` |
| 관리자 회원 폼 확장 | `MemberFormRenderingEvent` |
| 프론트 하단 HTML 주입 | `FrontFootRenderEvent` |
| 신규 도메인 생성 후 기본 데이터 시딩 | `DomainCreatedEvent` |
| 다운로드 권한 위임 | `SecureFileAccessEvent` |

> **주의:** 새 이벤트를 만들기 전에 [이벤트 시스템](event-system.md)의 안정 이벤트 목록을 먼저 확인하세요. 코어 내부 성격 이벤트에 과도하게 의존하면 플러그인 결합도가 높아집니다.

## 실제 예시: Banner 플러그인

Banner 플러그인의 구성:

| 요소 | 내용 |
|------|------|
| Provider | `register()`: Repository, Service, Controller, Renderer 등록 |
| Provider | `boot()`: AdminMenuSubscriber + BlockRegistry 등록 |
| Controller | 1개 (BannerController — 목록/등록/수정/정렬) |
| Service | 1개 (BannerService) |
| Repository | 1개 (BannerRepository) |
| Block | BannerRenderer + BannerConfigForm |
| Views | Admin (목록/폼/설치) + Block 스킨 |
| Migrations | 1개 (001_create_banners.sql) |
| 라우트 | 8개 (관리자 CRUD + 블록 API) |

이 규모가 "Plugin"의 전형적인 크기입니다.

## 실제 예시: FAQ 플러그인 (Contract 포함)

FAQ 플러그인은 Contract 패턴의 대표적인 예시입니다:

| 요소 | 내용 |
|------|------|
| Contract 구현 | `FaqService implements FaqQueryInterface` |
| Contract 바인딩 | `boot()`에서 `ContractRegistry::bind()` |
| 소비자 | Package/Plugin 컨트롤러에서 `ContractRegistry::resolve(FaqQueryInterface::class)`로 사용 |
| 추가 기능 | 프론트 FAQ 페이지 (`/faq`), 블록 렌더러, 설치 시 메뉴 자동 생성 |

## 패키지 종속 플러그인

특정 패키지 없이는 의미가 없는 플러그인(예: 게시판 신고)은 독립 플러그인이
아니라 **그 패키지 안에** 만든다. 규약은 코어 `NestedPlugin` 클래스 한 곳에
정의되어 있다.

전제: **부모 패키지 Provider 가 `PluginHostInterface` 를 구현한 경우에만
가능하다** ([패키지 만들기](package-development.md) "플러그인 수용하기" 참고).
코어는 패키지 내부를 스스로 읽지 않는다 — 구현하지 않은 패키지 안에
플러그인을 넣어도 코어는 무시한다.

```
packages/Board/Plugins/BoardReport/     ← 위치 (대문자 Plugins — PSR-4)
├── BoardReportProvider.php             네임스페이스: Mublo\Packages\Board\Plugins\BoardReport
├── manifest.json                       "parent": "Board" 명시 (아래 참고)
├── routes.php                          URL: /board/report/..., /admin/board/report/...
└── ...                                 나머지 구조는 독립 플러그인과 동일
```

manifest 에는 부모 패키지를 명시한다 — 배포물(zip)이 스스로 "어느 패키지용"
인지 말하게 하기 위해서다. 실제 설치 위치와 선언이 다르면 코어가 로드를
거부한다(엉뚱한 패키지에 풀어넣은 배포물의 조용한 오동작 방지).
`requires` 에 부모 의존은 적을 필요 없다(스캔 시 자동 주입).

```json
{
    "name": "BoardReport",
    "parent": "Board",
    ...
}
```

독립 플러그인과 다른 점:

| 항목 | 규칙 |
|------|------|
| 활성 이름 | `"Board/BoardReport"` — 확장 설정에 이 형태로 저장된다 |
| 종속 | 부모 패키지가 꺼지면 함께 꺼진다 (활성화 검증 + 런타임 이중 게이트) |
| 확장 화면 | 독립 카드가 아니라 부모 패키지 카드의 **부가 기능** 토글로 표시 |
| URL | 부모 패키지 URL 에 종속 — `/{패키지}/{플러그인}/...`. 플러그인 이름이 패키지 이름으로 시작하면 그 부분은 접힌다 (`BoardReport` → `report`) |
| 관리자 메뉴 | `addSubmenuTo('K_{패키지}', ...)` 로 부모 패키지 메뉴 아래에 |
| 에셋 URL | `/serve/plugin/{패키지}.{플러그인}/...` (경로 세그먼트라 `.` 표기) |
| 부모 접근 | 같은 패키지 안이므로 부모의 공개 서비스를 직접 사용해도 된다 |
| 마이그레이션 | `$runner->run('plugin', 'Board/BoardReport', __DIR__ . '/database/migrations')` |

언제 독립 플러그인으로, 언제 종속 플러그인으로?

- 여러 패키지·코어에 걸쳐 쓰이면 → 독립 플러그인 (`plugins/`)
- 특정 패키지의 기능을 확장할 뿐이면 → 종속 플러그인 (`packages/{Pkg}/Plugins/`)

주의: 뷰 경로에 `__DIR__ . '/../../views/...'` 처럼 `..` 를 쓰지 마세요 —
렌더러가 경로 조작 방지를 위해 차단합니다. `dirname(__DIR__, 2)` 를 사용합니다.

레퍼런스 구현: `packages/Board/Plugins/BoardReport` (신고 접수·관리자 목록·블라인드).

---

[< 이전: 패키지 만들기](package-development.md) | [다음: 확장 필수사항 >](extension-requirements.md)
