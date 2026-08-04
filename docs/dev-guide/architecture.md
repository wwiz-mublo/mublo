# 아키텍처 개요

## Mublo는 확장 플랫폼이다

Mublo Framework는 단순한 웹 프레임워크가 아니라 **확장 플랫폼**입니다.

핵심 철학은 "Core는 가볍게, 확장은 자유롭게"입니다. Core는 요청 흐름, 라우팅, DI, 인증, 렌더링, 이벤트, 관리자 기반 같은 공통 실행 규칙을 제공하고, 실제 업무 기능은 Plugin과 Package로 분리합니다.

```
                 ┌──────────────────────────┐
                 │          Core            │
                 │ request, routing, DI,     │
                 │ auth, rendering, events   │
                 └─────────────┬────────────┘
                               │
             ┌─────────────────┼─────────────────┐
             │                 │                 │
        Event Push        Contract Pull      Block Registry
             │                 │                 │
     ┌───────▼───────┐ ┌──────▼──────┐ ┌───────▼────────┐
     │    Plugin     │ │   Package   │ │  Page Builder   │
     │ Banner, FAQ   │ │ Board, Shop │ │ Block + Items   │
     └───────────────┘ └─────────────┘ └────────────────┘
```

멀티 도메인은 이 구조 위에 얹힌 운영 확장성입니다. 하나의 코드베이스에서 도메인별 설정, 회원, 권한, 테마, 활성 확장을 분리할 수 있습니다.

### Plugin vs Package

| 구분 | Plugin | Package |
|------|--------|---------|
| 역할 | Core 기능에 부가 기능 추가 | 독립적인 업무 영역 |
| 예시 | 배너, FAQ, 소셜 로그인, 포인트 | 게시판, 쇼핑몰, 독립 업무 도메인 |
| 규모 | 작음 (Service 1~3개) | 큼 (자체 Controller/Service/Repository) |
| 위치 | `plugins/{Name}/` | `packages/{Name}/` |
| 네임스페이스 | `Mublo\Plugin\{Name}` | `Mublo\Packages\{Name}` |
| 라우트 접두사 | 자동 적용 | 자동 적용 |
| 외부 배포 | 가능 | 가능 |

두 가지 모두 동일한 인터페이스(`ExtensionProviderInterface`)를 구현합니다.

```php
// src/Core/Extension/ExtensionProviderInterface.php

interface ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void;
    public function boot(DependencyContainer $container, Context $context): void;
}
```

- `register()` — DI 컨테이너에 서비스 등록 (Context 생성 전)
- `boot()` — 이벤트 Subscriber, 블록, Contract 바인딩 등록 (Context 사용 가능)

## 5대 핵심 축

### 1. Light Core

Core는 특정 업무 도메인을 직접 품기보다 공통 실행 규칙을 제공합니다.

- Application boot/run
- Request / Context
- Router / Dispatcher
- DI Container
- Middleware
- Response / Renderer
- EventDispatcher
- ContractRegistry
- BlockRegistry

게시판, 쇼핑몰, 배너, 팝업, FAQ 같은 기능은 Core가 아니라 Package/Plugin으로 분리합니다.

### 2. Extension Model

기능은 Plugin과 Package로 분리합니다.

- Plugin: 작고 수평적인 기능 확장
- Package: 자체 MVC와 데이터 구조를 가진 독립 애플리케이션

두 유형 모두 Provider를 통해 Core에 붙고, Event, Contract, Block을 사용해 필요한 지점에서만 연결됩니다.

### 3. Event / Contract

Mublo는 명시적 이벤트 클래스와 Contract 인터페이스로 확장 경계를 정의합니다.

- 상태 변화나 후처리는 Event
- 데이터 조회나 기능 호출은 Contract

이 기준은 확장 모듈 간 결합을 낮추고, 어떤 기능이 어떤 지점에 붙는지 코드와 문서에서 추적 가능하게 만듭니다.

### 4. Block / Block Item

블록 시스템은 운영자가 코드 수정 없이 페이지를 구성하는 페이지 빌더입니다. 행(Row)과 열(Column) 기반으로 블록을 배치하고, 각 블록에 콘텐츠 타입(HTML, 배너, 게시판 최신글 등)을 지정합니다.

Plugin과 Package는 Provider의 `boot()`에서 자신의 블록 콘텐츠 타입을 등록합니다. 또한 블록 아이템 공급자를 통해 게시판, 상품, 배너 같은 선택 가능한 출력 항목을 제공할 수 있습니다.

```php
// src/Core/Block/BlockRegistry.php

BlockRegistry::registerContentType(
    type: 'board',                          // 콘텐츠 타입 코드
    kind: BlockContentKind::PACKAGE->value, // CORE / PLUGIN / PACKAGE
    title: '최신 게시글',
    rendererClass: BoardRenderer::class,    // RendererInterface 구현
    configFormClass: BoardConfigForm::class,
    options: ['hasItems' => true, 'skinBasePath' => '...']
);
```

### 5. Multi-domain Context

하나의 코드베이스로 여러 사이트를 운영합니다. `Context` 객체가 요청마다 현재 도메인 정보를 캡슐화하고, 도메인별로 회원, 설정, 테마, 권한을 완전히 분리합니다.

```php
// src/Core/Context/Context.php 주요 필드

protected ?string $domain;          // 현재 도메인 문자열
protected ?Domain $domainInfo;      // 도메인 설정 Entity
protected bool $isAdmin;            // 관리자 영역 여부
protected bool $isApi;              // API 요청 여부
protected string $frameSkin;        // 프론트 프레임 스킨
protected string $adminSkin;        // 관리자 스킨
protected array $frontSkins;        // 프론트 스킨 [group => skin]
protected array $blockSkins;        // 블록 스킨 [type => skin]
```

모든 Service는 `$domainId`를 명시적으로 받아 도메인 경계를 보장합니다. Repository는 `domain_id`로 쿼리를 필터링합니다.

## Plugin (Core 기능 확장)

Plugin은 이벤트 시스템을 통해 Core에 기능을 추가합니다. 회원가입 시 포인트 지급, 관리자 메뉴 추가, 로그인 폼에 소셜 로그인 버튼 삽입 등이 대표적인 예입니다.

```php
// plugins/MemberPoint/MemberPointProvider.php

public function boot(DependencyContainer $container, Context $context): void
{
    $eventDispatcher = $container->get(EventDispatcher::class);
    $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
    $eventDispatcher->addSubscriber(new MemberEventSubscriber($pointService, $memberRepo));
}
```

Plugin이 다른 Package에 데이터를 제공해야 할 때는 **Contract 패턴**을 사용합니다. 예를 들어 FAQ Plugin은 `FaqQueryInterface`를 구현하고, 이를 필요로 하는 Package는 `ContractRegistry`에서 꺼내 씁니다.

실무 기준:
- UI 주입, 후처리, 상태 변화 반응 → Event
- 데이터 제공, 구현체 선택, 기능 호출 → Contract

관련 문서:
- [이벤트 시스템](event-system.md)
- [Contract 시스템](contract-system.md)

## Package (독립 애플리케이션)

Package는 자체 Controller, Service, Repository, Entity, View, 마이그레이션을 가진 독립 모듈입니다. Board(게시판), Shop(쇼핑몰) 등이 Package입니다.

```
packages/Board/
├── BoardProvider.php          # 진입점
├── manifest.json              # 메타데이터
├── routes.php                 # URL 라우팅
├── Controller/
│   ├── Admin/                 # 관리자 컨트롤러
│   └── Front/                 # 프론트 컨트롤러
├── Service/                   # 비즈니스 로직
├── Repository/                # 데이터 접근
├── Entity/                    # 도메인 모델
├── Enum/                      # 상태/타입 Enum
├── Event/                     # 도메인 이벤트
├── Subscriber/                # 이벤트 구독자
├── Block/                     # 블록 렌더러
├── views/                     # 뷰 템플릿
└── database/
    └── migrations/            # DB 마이그레이션
```

## 계층별 책임

코드는 4계층으로 분리됩니다. 각 계층은 아래 계층만 호출할 수 있습니다.

```
Controller → Service → Repository → Entity
```

### Controller

HTTP 요청을 받아 Service를 호출하고 Response를 반환합니다.

```php
// src/Controller/Admin/MemberController.php

public function store(array $params, Context $context): JsonResponse
{
    $data = FormHelper::normalizeFormData($params['formData'] ?? [], $this->getFormSchema());
    $result = $this->memberAdminService->register($data);

    return $result->isSuccess()
        ? JsonResponse::success(['redirect' => '/admin/member'], $result->getMessage())
        : JsonResponse::error($result->getMessage());
}
```

**규칙:**
- 비즈니스 로직 금지 (Service에 위임)
- DB 직접 접근 금지 (Repository에 위임)
- 반환 타입: `ViewResponse`, `JsonResponse`, `RedirectResponse`, `FileResponse`

### Service

비즈니스 로직을 담당하고, `Result` 객체를 반환합니다.

```php
// src/Core/Result/Result.php

$result = Result::success('회원이 등록되었습니다.', ['member_id' => $id]);
$result = Result::failure('아이디가 이미 존재합니다.');

$result->isSuccess();       // bool
$result->isFailure();       // bool
$result->getMessage();      // string
$result->getData();         // array
$result->get('member_id');  // mixed
```

**규칙:**
- Request/Response 직접 처리 금지
- Repository를 통해서만 DB 접근
- 이벤트 발행 가능 (`$this->dispatch(new SomeEvent(...))`)

### Repository

DB 쿼리를 실행하고 Entity를 반환합니다. `BaseRepository`를 상속하면 기본 CRUD가 제공됩니다.

```php
// src/Repository/BaseRepository.php 주요 메서드

public function find(int|string $id): ?object;
public function findBy(array $conditions): array;
public function findOneBy(array $conditions): ?object;
public function create(array $data): int|null;
public function update(int|string $id, array $data): int;
public function delete(int|string $id): int;
public function paginate(int $page, int $perPage): array;
```

```php
// src/Repository/Member/MemberRepository.php

class MemberRepository extends BaseRepository
{
    protected string $table = 'members';
    protected string $entityClass = Member::class;
    protected string $primaryKey = 'member_id';

    public function findByDomainAndUserId(int $domainId, string $userId): ?Member { ... }
}
```

**규칙:**
- 비즈니스 로직 금지 (조건 판단은 Service에서)
- Entity 반환 (배열이 아닌 객체)

### Entity

데이터를 표현하는 값 객체입니다. DB 접근이나 비즈니스 로직은 포함하지 않습니다.

```php
// src/Entity/Member/Member.php

class Member extends BaseEntity
{
    // fromArray()로 생성, getter로 접근
    public function getMemberId(): int;
    public function getUserid(): string;
    public function getLevelValue(): int;
    public function isActive(): bool;
}
```

## 네이밍 규칙

| 대상 | 패턴 | 위치 | 예시 |
|------|------|------|------|
| Controller | `{Name}Controller` | `src/Controller/{Area}/` | `MemberController` |
| Service | `{Name}Service` | `src/Service/{Domain}/` | `AuthService` |
| Repository | `{Name}Repository` | `src/Repository/{Domain}/` | `MemberRepository` |
| Entity | `{Name}` | `src/Entity/{Domain}/` | `Member` |
| Event | `{Name}Event` | `src/Service/{Domain}/Event/` 또는 `src/Core/Event/{Domain}/` | `MemberRegisteredEvent` |
| Subscriber | `{Name}Subscriber` | 각 모듈 내 | `AdminMenuSubscriber` |
| Middleware | `{Name}Middleware` | `src/Core/Middleware/` | `AdminMiddleware` |
| Provider | `{Name}Provider` | 각 모듈 루트 | `BoardProvider` |

## 확장 포인트 요약

Plugin/Package가 Core에 개입할 수 있는 모든 방법:

| 방법 | 용도 | 예시 |
|------|------|------|
| **이벤트 구독** | 상태 변화에 반응 (push) | 회원가입 시 포인트 지급, 관리자 메뉴 추가 |
| **Contract 구현** | 데이터 제공 (pull) | FAQ 조회, 결제 게이트웨이, 알림 발송 |
| **블록 등록** | 페이지 빌더에 콘텐츠 타입 추가 | 게시판 최신글, 배너, 설문 블록 |
| **대시보드 위젯** | 관리자 대시보드에 위젯 추가 | 방문자 통계, 최근 공지 |
| **라우트 등록** | routes.php로 URL 추가 | `/admin/myplugin/settings` |
| **마이그레이션** | DB 테이블 추가 | `database/migrations/*.sql` |

### 현재 철학

Mublo의 확장 지점은 명시적인 Event 클래스와 Contract 인터페이스로 제공한다.

대신:
- 코어가 명시적 이벤트 클래스를 발행하고
- 확장이 `Provider.boot()`에서 subscriber를 등록하며
- 필요할 때만 Contract로 느슨한 호출 관계를 만든다

즉 확장 철학은 다음에 가깝다.

1. 기본은 Event
2. 조회/호출은 Contract
3. 새 확장 포인트 추가보다 기존 안정 이벤트 재사용 우선

코어 이벤트의 실제 발행 지점과 안정성 분류는 [이벤트 시스템](event-system.md)에서 확인할 수 있다.

---

[< 개발자 가이드 목록](README.md) | [다음: 요청 흐름 >](request-lifecycle.md)
