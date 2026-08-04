# 12. Plugin

Plugin은 두 종류다. 코어 위에서 독립적으로 동작하는 **독립 Plugin**과, 특정 Package 없이는 의미가 없어 그 Package 안에 사는 **종속 Plugin**. 두 종류는 배치·발견·생명주기 규약이 다르다.

## 독립 Plugin

```text
plugins/{Name}/
├── {Name}Provider.php   # 선택. Mublo\Plugin\{Name}\{Name}Provider
├── manifest.json        # 필수
├── routes.php           # 선택
└── database/migrations/ # 선택
```

- Provider FQCN 규약: `Mublo\Plugin\{Name}\{Name}Provider` (`src/Core/Extension/ExtensionManager.php`).
- Provider가 없어도 된다 — manifest와 라우트·뷰만으로 구성되는 단순 Plugin은 Provider 없이 로드 목록에 기록되고 register/boot는 건너뛴다.
- 활성 목록의 키는 디렉토리명(`Banner` 등)이다.

## 종속 Plugin

특정 Package의 생태계에 속하는 Plugin이다. 식별 규약은 `src/Core/Extension/NestedPlugin.php`에 고정돼 있다.

```text
활성 키(활성 목록·manifest 키):  {Package}/{Name}     예: Board/BoardReport
표준 디렉토리:                  packages/{Package}/Plugins/{Name}/
표준 네임스페이스:              Mublo\Packages\{Package}\Plugins\{Name}\
에셋 URL 세그먼트:              {Package}.{Name}      예: /serve/plugin/Board.BoardReport/...
Migration 이력:                source=plugin, name={Package}/{Name}
```

### 발견의 주체는 Package다

코어는 `packages/` 내부 디렉토리를 스스로 스캔하지 않는다.

1. Package Provider가 `PluginHostInterface`를 구현한 경우에만 코어가 `discoverPlugins()`를 호출한다.
2. Package가 답한 위치의 manifest만 읽는다 (`ExtensionService::scanNestedPluginManifests()`).
3. 표준 디렉토리 규약을 쓰는 Package는 `use PluginHostTrait;` 한 줄로 충분하다.
4. `PluginHostInterface`를 구현하지 않은 Package에 Plugin을 넣어도 존재하지 않는 것으로 취급된다.

발견 결과는 요청 스코프로 캐시된다(`NestedPlugin::$discovered`). `discoverPlugins()`는 register/boot 전에 호출될 수 있으므로 Container·DB에 의존하면 안 된다.

### 부모 종속 규칙

코어가 보장하는 규칙들이다. Plugin 개발자가 방어 코드를 쓸 필요가 없다.

| 규칙 | 구현 위치 |
|---|---|
| manifest `parent`와 실제 설치 위치가 다르면 로드 거부 | `ExtensionService::scanNestedPluginManifests()` |
| `requires["package:{Package}"]` 안전망(`*`) 자동 주입 | 같은 곳 |
| 같은 도메인에서 부모가 비활성이면 Plugin도 비활성 | `ExtensionService::getEnabledPlugins()` |
| 부모 Package가 항상 먼저 register/install/boot | `ExtensionManager`, `ExtensionService::executeLifecycle()` |
| 비활성화·uninstall은 Plugin이 먼저 (역순) | `ExtensionService::executeLifecycle()` |
| 부모 register/boot 실패 시 Plugin 실행 차단 + 진단 기록 | `ExtensionManager` (dependency skip) |
| 부모 비활성·실패 시 Plugin의 Route·Asset 노출 차단 | `src/Core/App/Router.php`, `src/Controller/Api/ServeController.php` |
| 부모 활성 판정은 install·reconcile 경로에도 동일 적용 | `ExtensionService` |

부모 의존성은 **같은 도메인** 안에서만 충족된다. 도메인 A에서 Board가 활성이어도 도메인 B의 `Board/BoardReport` 의존성은 충족되지 않는다.

### 부모에 대한 의존 방법

종속 Plugin이 부모 Package에서 사용할 수 있는 것은 공개 표면뿐이다.

- 허용: `Mublo\Packages\{Package}\Contract\Extension\*`, `Api\DTO\*`, 공식 `Event\*`
- 금지: 부모의 Service, Repository, Entity, Helper, Controller, DB 테이블 직접 접근, Session key

이 경계는 `php tools/check-extension-api.php`가 검사한다. 상세 규약은 [15. Public API](15-public-api.md).

```php
// BoardReport의 표준 패턴 — 부모 접근은 공개 Contract 하나로 수렴한다
$container->singleton(BoardReportService::class, fn($c) =>
    new BoardReportService(
        $c->get(BoardReportRepository::class),
        $c->get(BoardExtensionApiInterface::class)   // 부모의 공개 API
    )
);
```

## 독립 Plugin vs 종속 Plugin 선택 기준

| 질문 | 독립 Plugin | 종속 Plugin |
|---|---|---|
| 특정 Package 없이 의미가 있는가 | 있다 (배너, 팝업 등) | 없다 (게시글 신고, 북마크 등) |
| 부모 버전 호환을 선언해야 하는가 | 불필요 | `requires["package:{Pkg}"]` 명시 권장 |
| 배포 위치 | `plugins/` | 부모 Package의 `Plugins/` |
| 누가 수용을 결정하는가 | 코어 | 부모 Package |

## Migration

종속 Plugin의 Migration은 자기 디렉토리의 `database/migrations/`에 두고, 이력은 전체 활성 키로 기록된다: `(source='plugin', name='Board/BoardReport', file)`. 부모 Package와 이름이 같아도 이력이 분리된다 (`src/Core/Extension/MigrationRunner.php`).

## Best Practice

- 종속 Plugin은 부모 접근을 공개 Contract 주입 하나로 수렴시킨다 — 부모 내부가 리팩터링돼도 Plugin이 깨지지 않는다.
- `requires["package:{Pkg}"]`에 실제로 검증한 버전 범위를 적는다. 자동 주입되는 `*`는 안전망이지 선언이 아니다.
- 부모 미설치·비활성·버전 불일치 시나리오를 테스트에 포함한다.

## 관련 문서

- [11. Package](11-package.md) · [13. Manifest](13-manifest.md) · [14. Extension](14-extension.md) · [30. Plugin Guide](30-plugin-guide.md)
- 레퍼런스 구현: `packages/Board/Plugins/BoardReport/` ([33. Reference Packages](33-reference-packages.md))
- 확장 요구사항 규정: `docs/dev-guide/extension-requirements.md`
