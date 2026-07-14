# 14. Extension

이 장은 확장(Package·Plugin)이 발견되고, 검증되고, 로딩되고, 설치되고, 실패가 격리되는 전체 런타임을 다룬다. 구현은 `src/Core/Extension/`(런타임)과 `src/Service/Extension/`(관리 lifecycle)에 나뉘어 있다.

## 세 개의 상태 축

Mublo의 확장 상태는 범위가 다른 세 축으로 구분된다. 이 구분을 혼동하면 멀티 도메인에서 반드시 사고가 난다.

```text
전역 (서버에 배포된 코드)          도메인 (extension_config)        요청
├── 확장 발견 결과·Manifest        ├── 활성 목록 (plugins/packages)  ├── Provider 인스턴스
├── Provider/Route/Asset 경로      ├── installed 마킹                ├── register/boot 결과
└── schema_migrations (실행 이력)  ├── install/uninstall 훅 실행     └── ExtensionLoadDiagnostics
                                   └── super_only·mandatory 규칙
```

- Migration은 전역에서 한 번 실행된다. 도메인 B에서 같은 확장을 처음 활성화하면 Migration은 no-op이지만 install 훅은 도메인 B에서 실행되고 installed 마킹도 도메인 B의 config에 남는다.
- 의존성 판정(부모 활성 여부, requires)은 항상 **같은 도메인의 활성 목록** 기준이다.

## 발견과 검증

```text
scanManifests (plugins/, packages/)          독립 Plugin·Package
+ scanNestedPluginManifests                  종속 Plugin — Package의 discoverPlugins() 응답만 신뢰
→ readManifest 정규화 (기본값, name=디렉토리, vendor 검증)
→ parent-실제 위치 일치 검사, requires["package:*"] 자동 주입
```

활성화 시점 검증 (`ExtensionService::validateExtensionConfig()`):

1. manifest 존재 확인 (종속 Plugin은 `NestedPlugin::dir()` 기준)
2. `requires` 호환성 — `ExtensionCompatibility::check()`가 코어 버전과 "이 설정을 적용한 뒤의 같은 도메인 설치 상태"를 기준으로 판정

## 요청 Runtime — register와 boot

`ExtensionManager::loadExtensions()`의 고정 순서:

```text
1. 활성 Package 전체 register        (부모가 항상 먼저)
2. 활성 Plugin 전체 register         (종속 Plugin은 부모 실패·비활성 시 제외)
3. 모든 register 완료
4. 같은 순서로 전체 boot             (Package boot → Plugin boot)
```

- `register()`는 Container 정의 등록만, `boot()`는 Event 구독 등 연결만 한다. 이 경계 덕에 boot 시점에는 모든 확장의 서비스 정의가 준비돼 있다.
- 부모 Package가 register 또는 boot에 실패하면 그 종속 Plugin은 실행되지 않고 `dependency` 단계 실패로 진단에 기록된다.
- **동층위(Package↔Package, Plugin↔Plugin) 로딩 순서는 보장하지 않는다 — 의도적 정책이다.** 확장 간 상호 직접 참조는 금지이고(계약은 lazy resolve, 이벤트 구독은 boot 완료 후 dispatch), 원칙을 지키는 확장에게 로딩 순서는 관찰 불가능하다. 따라서 확장은 **다른 확장의 로딩 순서에 의존하는 코드를 작성해서는 안 된다** — 코어가 순서를 보장해 주면 순서 의존(=원칙 위반) 코드가 안정 동작해 잘못된 신호가 되므로 위상정렬을 제공하지 않는다. `requires` 는 활성화 가능 여부 검증(findIncompatible) 전용이다.

## 실패 격리와 전파

`ExtensionManager::handleExtensionError()`의 정책:

| 조건 | 동작 |
|---|---|
| 일반 확장 실패 | 격리 — 로그 + 진단 기록, 나머지 확장 정상 동작 |
| `critical: true` 확장 실패 | `RuntimeException` 전파 — 사이트가 그 확장 없이 뜨면 안 되는 경우 |
| `APP_DEBUG=true` | 모든 실패 전파 (개발 중 조기 발견) |

실패는 `ExtensionLoadDiagnostics`(요청 스코프)에 구조화되어 기록되고, 관리자 진단 화면과 아래 노출 게이트가 함께 사용한다.

## 노출 게이트 — Route와 Asset

활성 상태와 실행 성공 여부는 라우트·에셋 노출에도 동일하게 적용된다. 확장이 코드로는 존재해도:

- 비활성이면 → Router가 라우트를 등록하지 않는다 (조회 실패 시 fail-closed)
- 이번 요청에서 register/boot/dependency 실패했으면 → `Router::withoutFailedExtensions()`가 라우트를 제외한다 (영속 설정은 유지 — 다음 요청에서 복구되면 다시 노출)
- 종속 Plugin의 부모가 비활성이면 → Plugin의 Route와 Asset(`ServeController`)도 차단된다

## 관리 Lifecycle — 활성화·비활성화

`ExtensionService::saveExtensionConfig()` → `executeLifecycle()`:

```text
활성화:   Package 처리 → Plugin 처리        (부모 먼저)
비활성화: Plugin 처리 → Package 처리        (자식 먼저, 역순)
```

신규 활성 + 미설치 확장 1건의 처리:

```text
부모 활성 검증 (종속 Plugin, 같은 도메인)
→ register
→ Migration 실행 — 실패 시 여기서 중단 (예외 승격)
→ install()
→ installed 마킹
실패 시: 활성 목록에서 제거, installed 마킹 없음
```

비활성화 시에는 `uninstall()`이 호출되며 데이터는 보존한다. 현재의 `uninstall()`은 실질적으로 disable 의미다 — install/enable/disable/uninstall/purge를 분리하는 lifecycle v2는 [34. Technical Roadmap](34-roadmap.md).

### 첫 부팅 reconcile

설치기·서브도메인 시드는 활성 목록만 기록하고 install을 못 돌린다(그 시점엔 Container가 없다). `reconcileDefaultExtensions()`가 부팅 후 "활성인데 미설치"인 갭을 Package → Plugin 순서로 메운다. 종속 Plugin은 같은 도메인의 부모 활성 판정을 통과해야 하며, 실패하면 마킹을 보류하고 다음 요청에 재시도한다.

## Migration 실행과 추적

`src/Core/Extension/MigrationRunner.php`:

- 실행 단위: `run(source, name, migrationPath)` — source는 `core`/`plugin`/`package`
- 추적: `schema_migrations` 테이블, 고유키 `(source, name, file)`와 실행 당시 SHA-256 checksum. 종속 Plugin의 name은 전체 활성 키(`Board/BoardReport`)
- 실행 순서: 파일명 오름차순. 실행된 파일은 재실행하지 않는다
- 드리프트: 실행 이력의 checksum과 현재 파일이 다르면 pending 실행 전에 중단한다. checksum 도입 이전 이력은 현재 파일을 최초 기준으로 등록한다
- 실패: `['success' => false, 'error' => ...]` 반환 — 관리 lifecycle은 이를 예외로 승격해 install을 중단한다
- 멱등 허용 오류: `MigrationErrorPolicy`가 실제 DDL 문맥과 대상 식별자를 확인한 중복 ADD·존재하지 않는 DROP만 무시한다
- `-- @optional-table: a, b` 주석: 해당 테이블 부재로 인한 오류를 무시 (선택적 연동 테이블)

## 요청 스코프 캐시

- `NestedPlugin::$hosts` / `$discovered` — 발견 결과 캐시 (요청 단위)
- `ExtensionService::$domainByIdCache` — 도메인 조회 메모이즈. 설정 저장·installed 마킹 시 무효화
- 도메인·라우트 캐시 — 설정 저장 시 `DomainCache`·`Router::clearRouteCache()` 무효화

## Best Practice

- 확장 실패를 재현·진단할 때는 `APP_DEBUG=true`로 전파시켜 스택을 확인한다.
- `critical: true`는 "이 확장 없이 사이트가 뜨면 더 위험한" 확장에만 선언한다 — 실패가 요청 전체를 죽인다.
- install()과 Migration은 언제나 멱등으로 작성한다. reconcile·재활성화가 재호출을 전제한다.

## 관련 문서

- [11. Package](11-package.md) · [12. Plugin](12-plugin.md) · [13. Manifest](13-manifest.md) · [05. Router](05-router.md)
- Migration 추적 규약: `docs/dev-guide/database.md`, `docs/reference/database-schema.md`
- ZIP 배포 신뢰 규약: `docs/dev-guide/extension-signing.md`
- 장기 구조(Descriptor·Catalog·Resolver): [34. Technical Roadmap](34-roadmap.md)
