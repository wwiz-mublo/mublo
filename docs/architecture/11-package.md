# 11. Package

Package는 Mublo에서 애플리케이션 기능을 담는 단위이자, 종속 Plugin을 수용해 자기 생태계를 운영할 수 있는 플랫폼 단위다.

## 책임과 비책임

Package가 하는 것:

- 도메인 기능 구현 (Service, Repository, Controller, View)
- Container에 자기 서비스 등록, Event 구독자 연결
- 자기 라우트(`routes.php`)와 DB Migration 관리
- (선택) 종속 Plugin 수용과 공개 Extension API 제공

Package가 하지 않는 것:

- Core 수정 — 필요한 연결은 Event와 Container로만 한다
- 다른 Package 내부 참조 — 필요하면 상대의 공개 Contract를 사용한다

## 표준 구조

```text
packages/{Name}/
├── {Name}Provider.php      # 필수. Mublo\Packages\{Name}\{Name}Provider
├── manifest.json           # 필수. 없으면 발견되지 않는다
├── routes.php              # 선택. 활성 시에만 Router가 로드
├── Contract/Extension/     # 선택. 종속 Plugin용 공개 Contract
├── Api/                    # 선택. Contract 구현체(@internal)와 DTO
├── Event/                  # 선택. 공식 Event (공개 표면)
├── Service/  Repository/  Entity/  Controller/   # 내부 구현
├── database/migrations/    # *.sql — 활성화 시 실행
├── database/seeders/       # *.php — 도메인 생성 시 실행
└── views/                  # 스킨·템플릿
```

Provider 클래스명은 규약으로 고정된다. `src/Core/Extension/ExtensionManager.php`가 `Mublo\Packages\{Name}\{Name}Provider`를 조립해 로드하며, 클래스가 없으면 Provider 없는 Package로 기록하고 넘어간다.

## Provider

Package Provider는 `src/Core/Extension/ExtensionProviderInterface.php`를 구현한다.

- `register(DependencyContainer $container)` — Container 정의 등록만 한다. 다른 확장의 서비스가 아직 등록되지 않았을 수 있으므로 여기서 서비스를 꺼내 쓰지 않는다.
- `boot(DependencyContainer $container, Context $context)` — 모든 확장의 register가 끝난 후 호출된다. Event 구독자 등록 등 요청 Context 기반 연결을 한다.

설치 훅이 필요하면 `src/Core/Extension/InstallableExtensionInterface.php`를 추가로 구현한다.

- `install()` — 첫 활성화 시 1회 (메뉴 등록, 초기 설정). 멱등이어야 한다.
- `uninstall()` — 비활성화 시 호출. 데이터는 보존한다 (실질적 의미는 disable — [34. Technical Roadmap](34-roadmap.md)의 lifecycle v2 참조).

실제 예 (`packages/Board/BoardProvider.php`):

```php
class BoardProvider implements ExtensionProviderInterface, InstallableExtensionInterface, PluginHostInterface
{
    use PluginHostTrait;   // Plugins/ 디렉토리 규약으로 종속 Plugin 발견

    public function register(DependencyContainer $container): void
    {
        $container->singleton(BoardArticleService::class, /* ... */);
        // 공개 Extension API — 내부 Service를 Contract 뒤에 바인딩
        $container->singleton(BoardExtensionApiInterface::class, fn($c) =>
            new BoardExtensionApi(
                $c->get(BoardArticleReaderInterface::class),
                $c->get(BoardArticleCommandInterface::class)
            )
        );
    }
}
```

## 생명주기

### 활성화 (관리자)

`src/Service/Extension/ExtensionService.php`의 `saveExtensionConfig()`가 처리한다.

```text
유효성 검증 (manifest 존재 + requires 호환성)
→ 정규화, mandatory 잠금
→ [신규 활성 + 미설치] register → migration → install → installed 마킹
→ 도메인 extension_config 저장
→ 도메인·라우트 캐시 무효화
```

- Migration이 실패하면 install은 실행되지 않고 installed로 마킹되지 않으며, 해당 확장은 활성 목록에서 제거된다.
- 활성화 순서는 Package가 종속 Plugin보다 항상 먼저다. 비활성화는 역순이다.

### 요청 Runtime

`ExtensionManager::loadExtensions()` — 활성 Package 전체를 먼저 register하고, 그다음 Plugin을 register하며, 모든 register가 끝난 후 같은 순서로 boot한다. Package가 register 또는 boot에 실패하면:

- 그 Package의 종속 Plugin은 register/boot되지 않는다 (dependency skip 기록).
- `manifest.json`에 `critical: true`가 선언된 확장의 실패는 요청 전체로 전파된다. 그 외에는 격리되어 나머지 확장이 정상 동작한다.
- 실패는 `src/Core/Extension/ExtensionLoadDiagnostics.php`에 기록되고, 이번 요청에서 해당 확장의 라우트는 노출되지 않는다 ([05. Router](05-router.md)).

### 첫 부팅 reconcile

설치기가 활성 목록에 넣었지만 install을 돌리지 못한 확장은 부팅 시 `reconcileDefaultExtensions()`가 Package → Plugin 순서로 migration + install을 수행해 수렴시킨다. 실패하면 installed 마킹을 보류하고 다음 요청에 재시도한다.

## Migration과 Seeder

- Migration: `database/migrations/*.sql`. 이름순으로 실행되고 `schema_migrations`에 `(source='package', name={Name}, file)` 고유키로 추적된다 (`src/Core/Extension/MigrationRunner.php`). 실행 이력은 전역이다 — 같은 서버의 다른 도메인이 이미 실행했으면 no-op.
- Seeder: `database/seeders/*.php`. 각 파일은 `fn(PDO $pdo, int $domainId)` 형태의 callable을 반환하며, 새 도메인 생성 시 도메인별로 실행된다.

## 도메인별 활성 상태

Package의 활성·installed 상태는 도메인별 `extension_config`에 저장된다. 도메인 A에서 활성이어도 도메인 B에서는 비활성일 수 있으며, **한 도메인의 활성 상태는 다른 도메인의 의존성을 충족하지 않는다.** Migration(전역)과 install 훅(도메인별)의 구분은 [14. Extension](14-extension.md) 참조.

## Package를 플랫폼으로 만들기

Package Provider가 `PluginHostInterface`를 구현하면 종속 Plugin을 수용한다.

- 표준 디렉토리 규약(`packages/{Name}/Plugins/{Plugin}/`)을 쓰려면 `use PluginHostTrait;` 한 줄이면 된다 (`src/Core/Extension/PluginHostTrait.php`).
- 다른 발견 방식이 필요하면 `discoverPlugins()`를 직접 구현한다. 이 메서드는 register/boot 전에 호출될 수 있으므로 Container·DB에 의존하면 안 된다 (`src/Core/Extension/PluginHostInterface.php`).
- 구현하지 않은 Package에 Plugin을 넣어도 코어는 무시한다 — 수용 여부는 Package 개발자가 결정한다.

플랫폼이 된 Package는 공개 표면을 선언할 책임을 진다: 종속 Plugin이 쓸 수 있는 것은 `Contract/Extension/*`, `Api/DTO/*`, 공식 `Event/*`뿐이다. 자세한 규약은 [15. Public API](15-public-api.md), 실물 사례는 [33. Reference Packages](33-reference-packages.md).

## Best Practice

- `register()`에서는 등록만, `boot()`에서는 연결만 한다.
- `install()`은 멱등으로 작성한다 — reconcile과 재활성화 경로에서 다시 호출될 수 있다.
- 내부 Service에 전부 Interface를 만들지 않는다. 종속 Plugin이 실제로 쓰는 작은 Contract만 공개한다.

## 관련 문서

- [12. Plugin](12-plugin.md) · [13. Manifest](13-manifest.md) · [14. Extension](14-extension.md) · [29. Package Guide](29-package-guide.md)
- 확장 요구사항 규정: `docs/dev-guide/extension-requirements.md`
- DB·Migration 가이드: `docs/dev-guide/database.md`
