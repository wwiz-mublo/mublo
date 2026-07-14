# 09. 멀티 도메인

Mublo는 한 설치본이 여러 도메인을 서비스한다. "어느 사이트의 요청인가"라는 질문의 답은 항상 Context의 `getDomainId()`에 있고, 활성 확장·설정·데이터·캐시가 전부 이 값으로 갈라진다. 이 장은 그 분리가 어디에서 어떻게 일어나는지를 다룬다. 뒤의 모든 장 — 특히 [14. Extension Runtime](14-extension.md) — 이 이 장의 상태 모델을 전제한다.

## 세 개의 상태 축

멀티 도메인에서 상태의 범위를 혼동하면 반드시 사고가 난다. Mublo의 모든 상태는 세 축 중 하나에 속한다.

```text
전역 (서버 1회)                 도메인 (도메인별 분리)              요청 (요청 1회)
├── 배포된 코드·Manifest        ├── extension_config (활성·installed)  ├── Context (해석 결과)
├── schema_migrations 실행 이력  ├── 도메인 설정·theme_config           ├── Provider 인스턴스
└── 발견 결과(코드 인벤토리)      ├── 콘텐츠 데이터 (게시판·회원·블록)     ├── register/boot 결과
                               ├── 라우트 테이블·라우트 캐시            └── ExtensionLoadDiagnostics
                               └── 캐시·에러 로그·에디터 경로
```

- DB 스키마(Migration)는 전역에서 한 번 변경된다. 그러나 확장의 install 훅과 installed 마킹은 도메인별이다 — 도메인 B에서 같은 확장을 처음 활성화하면 Migration은 no-op이고 install 훅만 도메인 B에서 실행된다.
- **한 도메인의 상태는 다른 도메인의 어떤 판정도 충족하지 않는다.** 도메인 A에서 Board가 활성이어도 도메인 B의 `Board/BoardReport` 부모 의존성은 충족되지 않는다.

## 도메인 해석 — 요청이 사이트를 만나는 순간

`ContextBuilder::build()`(`src/Core/Context/ContextBuilder.php`)가 요청의 Host를 `DomainResolver`(`src/Service/Domain/DomainResolver.php`)로 캐시 → DB 순서로 조회해 `Domain` 엔티티를 Context에 채운다. Host 값은 `Request::getHost()`가 정규화·검증한다 ([06. Request](06-request.md)).

이어서 `Application::run()`이 도메인 유효성을 검증한다 (`src/Core/App/Application.php`):

| 상태 | 처리 |
|---|---|
| 미등록(`not_found`) · 차단(`blocked`) · 계약 만료(`expired`) · 비활성(`inactive`) | `ErrorRenderer`로 에러 페이지 출력 후 종료 |
| `/install`, `/csrf/*`, `/serve/*` 경로 | 검증 생략 (설치·공개 API 경로) |

도메인 ID가 확정되면 캐시·에러 로그·에디터 경로가 도메인별로 분리 설정되고, 그 도메인의 활성 확장 구성이 로딩된다.

## 도메인별로 갈라지는 것들

| 대상 | 분리 방식 | 소스 |
|---|---|---|
| 활성 확장 구성 | 도메인별 `extension_config`(활성 목록 + installed 마킹) | `src/Service/Extension/ExtensionService.php` |
| 라우트 테이블 | 활성 확장이 도메인별이므로 라우트도 도메인별. 캐시 파일도 `storage/cache/routes/{domain}.{signature}.cache.php` | `src/Core/App/Router.php` ([05. Router](05-router.md)) |
| 테마·스킨 | 도메인의 `theme_config`로 프레임·콘텐츠·블록 스킨 결정 | `src/Core/Context/ContextBuilder.php` |
| 사이트 표시 | 로고·이미지 URL을 도메인 데이터 + `SiteContextReadyEvent` override로 구성 | [04. Context](04-context.md) |
| 캐시·에러 로그·에디터 경로 | 도메인 ID 확정 직후 분리 설정 | `src/Core/App/Application.php` |
| 회원 정책 | 도메인 설정 (예: `isUseEmailAsUserId()`) | `Domain` 엔티티 |
| AI 설정 | 도메인별 공급자·모델·암호화 키·일일 한도 | [27. AI 시스템](27-ai.md) |

## 도메인 계층과 super_only

도메인은 `domain_group`("루트ID/..." 형태)으로 계층을 이룬다. 루트 도메인이 하위 도메인을 관리하는 두 가지 공식 메커니즘이 있다.

### super_only Plugin

Manifest에 `super_only: true`를 선언한 Plugin은 루트 도메인이 제어권을 갖는다 (`src/Service/Extension/ExtensionService.php`):

- 루트 도메인에서 활성이면 하위 도메인에서도 **강제 활성**된다 (`applySuperOnlyPlugins()`).
- 하위 도메인의 설정 저장 요청에서 이 Plugin에 대한 직접 제어는 제거된다 — 하위 운영자가 켜고 끌 수 없다.
- 하위 도메인에서는 라우트도 차단된다 (`Router::isSuperOnlyPluginOnSubSite()`).

### 도메인 생성 시딩

새 도메인이 만들어지면 `DomainCreatedEvent`가 발행되어 각 Package가 초기 데이터를 시딩하고(Board의 기본 게시판 등), Package의 `database/seeders/*.php`가 `fn(PDO $pdo, int $domainId)` 시그니처로 도메인별 실행된다. 활성 목록에 있으나 install이 안 된 확장은 첫 부팅 reconcile이 도메인별로 수렴시킨다 ([14. Extension Runtime](14-extension.md)).

## 확장 개발자 체크리스트

멀티 도메인에서 안전한 확장의 조건이다.

- [ ] 도메인 데이터 분리가 필요한 모든 쿼리에 `$context->getDomainId()`를 조건으로 쓴다.
- [ ] `install(DependencyContainer, Context)`는 "지금 활성화가 일어난 도메인"의 초기화만 한다 — 전역 상태를 가정하지 않는다.
- [ ] Seeder는 `$domainId` 인자를 존중한다.
- [ ] 백그라운드·CLI 작업은 대상 `domainId`를 명시적으로 받는다 — "현재 요청의 도메인"이 없기 때문이다.
- [ ] 테스트에 "도메인 A 활성 + 도메인 B 비활성" 시나리오를 포함한다. 실물 예: `tests/Unit/Service/Extension/ExtensionServiceLifecycleOrderTest.php`의 멀티 도메인 케이스.

## Anti Pattern

- **다른 도메인의 활성 상태에 기대기** — 의존성 판정은 같은 도메인 안에서만 유효하다. 하위 도메인 강제 활성이 필요하면 super_only가 공식 경로다.
- **도메인 조건 없는 콘텐츠 쿼리** — 다른 사이트의 데이터가 섞인다. 전역 게시판처럼 의도된 공유는 해당 Package의 명시적 정책(예: Board의 `isGlobal()`)으로만 한다.

## 관련 문서

- [04. Context](04-context.md) — 도메인 해석 결과가 담기는 곳
- [05. Router](05-router.md) — 도메인별 라우트 게이트와 캐시
- [14. Extension Runtime](14-extension.md) — 전역 Migration vs 도메인별 install의 상세
- 운영자 가이드: `docs/user-guide/domain-management.md`
