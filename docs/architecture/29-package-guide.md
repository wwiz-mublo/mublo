# 29. Package Guide

새 Package를 현재 런타임 규약에 맞춰 배치하고 활성화하는 실전 가이드다. 이 장의 근거 구현은 `packages/Board/`와 `src/Core/Extension/ExtensionManager.php`, 설치 흐름은 `src/Service/Extension/ExtensionService.php`다. 개념은 [11. Package](11-package.md), Manifest 상세는 [13. Manifest](13-manifest.md)를 전제로 한다.

## 1. Package를 선택할 때

현재 저장소에서 Package는 Board와 Shop처럼 독립 업무 영역을 소유한다. 단일 화면이나 부가 기능이면 독립 Plugin이 더 작은 단위다. 다음 조건이 함께 필요할 때 Package를 선택한다.

- 자체 Controller·Service·Repository·테이블·화면을 소유한다.
- `routes.php`로 독립 URL 영역을 제공한다.
- 다른 개발자가 붙을 공개 Contract·DTO·Event가 필요하다.
- `PluginHostInterface`를 구현해 `packages/{Package}/Plugins/` 생태계를 열 필요가 있다.

## 2. 런타임이 실제로 찾는 최소 진입점

`ExtensionService::scanManifests()`는 `packages/*/manifest.json`을 스캔한다. 활성 Package의 Provider 클래스는 `ExtensionManager::loadPackage()`가 디렉토리명으로 다음처럼 조립한다.

```text
디렉토리: packages/Board/
Provider: Mublo\Packages\Board\BoardProvider
파일:     packages/Board/BoardProvider.php
```

실제 Board의 최상위 구조에서 역할별 핵심만 추리면 다음과 같다.

```text
packages/Board/
├── BoardProvider.php
├── manifest.json
├── routes.php
├── Controller/
├── Service/
├── Repository/
├── Entity/
├── Event/
├── Subscriber/
├── Contract/Extension/
├── Api/DTO/
├── database/migrations/
├── views/
└── Plugins/BoardReport/
```

Controller나 Event 등은 기능이 있을 때 추가하는 디렉토리다. 발견에 필요한 것은 `manifest.json`이고, 서비스를 등록하거나 이벤트를 연결하려면 이름 규약에 맞는 Provider가 필요하다. Provider 클래스가 없으면 목록에는 발견되지만 `register()`·`boot()` 동작은 없다(`ExtensionManager::loadPackage()`).

## 3. Manifest 작성

현재 `packages/Board/manifest.json`의 호환성 관련 핵심 필드는 다음과 같다.

```json
{
    "name": "Board",
    "label": "Mublo Board",
    "version": "1.0.0",
    "vendor": "mublo",
    "type": "package",
    "requires": {
        "core": ">=1.0.0"
    }
}
```

운영자 플래그(`default`/`mandatory`/`super_only`)는 개발·배포 단계에서 설정하지
않는다 — 배포의 운영 정책이며 운영자가 소비한다 ([13. Manifest](13-manifest.md)).

런타임에서 이름의 진실은 디렉토리다. `ExtensionService::readManifest()`는 manifest의 `name`이 달라도 경고 후 제거하고 디렉토리명을 다시 넣는다. `vendor`는 전역 표시 ID를 만들지만 서로 다른 vendor의 동명 디렉토리를 함께 설치하게 해 주는 네임스페이스는 아니다.

새 Package는 실제 검증한 Core 버전 범위만 `requires.core`에 기록한다. 검증하지 않은 상한·하한을 추정해서 넣지 않는다.

## 4. Provider — register와 boot

`BoardProvider`는 `ExtensionProviderInterface`를 구현한다. 실제 실행 순서는 `ExtensionManager::loadExtensions()`에서 확인된다.

1. 활성 Package 전체의 `register()`
2. 독립 Plugin과 종속 Plugin의 `register()`
3. 등록에 성공한 Provider 전체의 `boot()`

따라서 `register()`에는 자기 서비스 정의를 넣고, 다른 확장의 인스턴스를 꺼내는 연결 작업은 `boot()`로 미룬다. Board의 실제 패턴도 Repository·Service·Controller 싱글톤은 `register()`에 두고, `EventDispatcher::addSubscriber()`, 블록 타입, 대시보드 위젯 연결은 `boot()`에서 수행한다.

설치·비활성화 훅이 필요하면 `InstallableExtensionInterface`를 구현한다. 현재 `ExtensionService`는 활성화 시 Migration 후 `install()`, 비활성화 시 `uninstall()`을 호출한다. `uninstall()`은 영구 삭제 훅이 아니므로 확장 데이터를 지우지 않는다.

## 5. 라우트와 Migration

`packages/Board/routes.php`는 `PrefixedRouteCollector`를 반환받아 `addRoute()`와 `addRawRoute()`를 사용한다.

- `addRoute('GET', '/list', ...)` 같은 프론트 경로에는 Package 접두사가 붙는다.
- `/admin/...` 경로는 관리자 Package 접두사 아래에 놓인다.
- `addRawRoute()`는 Board의 `/community`처럼 접두사 없는 경로가 실제로 필요할 때만 사용한다.

Migration은 `database/migrations/*.sql`에 두며 파일명 오름차순으로 실행된다. 이력 키는 `(source='package', name='{DirectoryName}', file)`이다. `ExtensionService::runMigrationsOrFail()`에서 실패하면 `install()`로 진행하지 않는다.

배포 디렉토리를 `packages/{Name}/`에 배치한 뒤 관리자 확장 화면에서 해당 도메인에 활성화한다. 현재 활성 상태와 installed 마킹은 도메인별이고 Migration 이력은 전역이다.

## 6. Package를 확장 플랫폼으로 만들기

실제 레퍼런스는 Board와 그 종속 Plugin인 BoardReport다.

### 6.1 종속 Plugin 발견

`BoardProvider`는 `PluginHostInterface`를 구현하고 `PluginHostTrait`을 사용한다. 이 선언이 있어야 `ExtensionService::scanNestedPluginManifests()`가 `packages/Board/Plugins/*`를 발견한다. 선언하지 않은 Package 내부의 `Plugins/` 디렉토리는 코어가 임의로 스캔하지 않는다.

### 6.2 공개 표면

Board가 종속 Plugin에 공개한 표면은 다음 세 영역이다.

```text
packages/Board/Contract/Extension/
packages/Board/Api/DTO/
packages/Board/Event/
```

BoardReport의 `BoardReportService`는 `BoardExtensionApiInterface`를 주입받아 접근 가능한 게시글 Snapshot을 조회한다. Board의 내부 Service·Repository·Entity·테이블은 직접 참조하지 않는다. 새 Package도 실제 종속 Plugin이 요구하는 최소 동작만 Contract로 공개하고, 내부 Entity 대신 readonly DTO를 반환한다.

### 6.3 공식 Event

Event는 종속 Plugin이 부모 동작에 개입하거나 완료 사실을 받는 공개 표면이다. 발생 시점과 payload가 호환성 약속이 되므로 실제 발행 코드와 테스트를 함께 둔다. Board의 전체 공식 Event와 안정성 표시는 `packages/Board/README.md`가 관리한다.

## 7. 배포 전 검증

- [ ] 디렉토리명, Provider FQCN, `type: package`가 일치한다.
- [ ] `requires.core`에는 실제 테스트한 범위만 적었다.
- [ ] `register()`는 정의, `boot()`는 연결로 분리했다.
- [ ] Migration 실패 시 활성화되지 않는 경로를 확인했다.
- [ ] `install()`을 재호출해도 중복 데이터가 생기지 않는다.
- [ ] `uninstall()`이 비활성화 시 데이터를 삭제하지 않는다.
- [ ] 모든 데이터 조회·변경에 현재 `domainId` 범위를 적용했다.
- [ ] `php tools/check-extension-api.php`를 통과한다.
- [ ] 종속 Plugin을 수용한다면 공개 Contract·DTO·Event와 레퍼런스 Plugin을 문서화했다.

## 관련 문서

- [11. Package](11-package.md) · [14. Extension Runtime](14-extension.md) · [15. Public API](15-public-api.md)
- 상세 절차: `docs/dev-guide/package-development.md`
- 배포 규정: `docs/dev-guide/extension-requirements.md`
- 실물 레퍼런스: `packages/Board/README.md`, `packages/Board/Plugins/BoardReport/README.md`, [33. Reference Packages](33-reference-packages.md)
