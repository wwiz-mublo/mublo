# 08. Event

Event는 Plugin과 Package가 Core나 다른 확장의 코드를 수정하지 않고 기능을 연결하는 Mublo의 기본 확장 메커니즘이다. 명시적인 이벤트 클래스를 발행 지점에서 `dispatch()`하고 구독자가 받는 구조다. 이 장은 Event 시스템의 구성 요소와 시그니처, 발행·구독 흐름, 실패 정책, 그리고 공식 Event 카탈로그를 다룬다.

## 책임과 비책임

Event 시스템의 책임은 다음으로 한정된다.

- 이벤트 클래스명을 키로 리스너를 등록·정렬·호출한다.
- 이벤트 객체를 리스너에 순서대로 전달하고, 전파 중단·예외 정책을 적용한다.

다음은 Event 시스템의 책임이 아니다.

- 비동기 처리·큐잉: 모든 dispatch는 동기 호출이다. 큐는 존재하지 않는다.
- 구독자 자동 발견: 구독자는 각 확장의 Provider `boot()`에서 명시적으로 등록해야 한다. ([14. Extension](14-extension.md) 참조)
- 트랜잭션 관리: 트랜잭션 내부/외부 어디서 발행할지는 발행하는 쪽(Service)의 책임이다.

## 실제 구조

구성 요소는 네 개다. 모두 `src/Core/Event/` 아래에 있다.

### EventDispatcher — `src/Core/Event/EventDispatcher.php`

리스너 등록과 발송을 담당한다. 주요 시그니처는 다음과 같다.

```php
public function addListener(string $eventName, callable $listener, int $priority = 0): void
public function addSubscriber(EventSubscriberInterface $subscriber): void
public function removeListener(string $eventName, callable $listener): void
public function dispatch(EventInterface $event): EventInterface
public function getListeners(string $eventName): array
public function hasListeners(string $eventName): bool
```

- `$eventName`은 이벤트 클래스명(FQCN)이다. `priority`가 높을수록 먼저 실행된다(내부에서 `krsort` 정렬, 정렬 결과는 캐시).
- `dispatch()`는 리스너를 순서대로 호출하며, 매 리스너 호출 전에 `isPropagationStopped()`를 확인해 중단됐으면 나머지를 건너뛴다. 처리를 마친 이벤트 객체를 그대로 반환하므로, 발행자는 반환된 이벤트에서 구독자가 채운 결과를 읽는다.
- 생성자는 `?callable $exceptionLogger`를 받는다. 리스너에서 일반 예외(`\Throwable`)가 발생하면 기본적으로 이 로거에 기록하고 다음 리스너를 계속 실행한다(best-effort). 단 `\Error`(TypeError 등 치명 오류)는 항상 다시 던진다.

### EventInterface / AbstractEvent — `src/Core/Event/EventInterface.php`, `AbstractEvent.php`

모든 이벤트는 `EventInterface`를 구현한다. 인터페이스는 세 메서드뿐이다: `getName()`, `isPropagationStopped()`, `stopPropagation()`. 실무에서는 기본 구현인 `AbstractEvent`를 상속하며, `getName()`은 `static::class`를 반환한다 — 즉 이벤트명은 곧 클래스명이고, 별도의 이름 문자열을 관리하지 않는다.

### EventSubscriberInterface — `src/Core/Event/EventSubscriberInterface.php`

여러 이벤트를 한 클래스에서 구독할 때 쓴다. 정적 메서드 하나를 요구한다.

```php
public static function getSubscribedEvents(): array;
```

반환 형식은 세 가지를 지원한다 (`EventDispatcher::addSubscriber()`가 파싱).

```php
[
    EventClass::class => 'methodName',
    EventClass::class => ['methodName', $priority],
    EventClass::class => [['method1', $priority1], ['method2', $priority2]],
]
```

### FailFastEventInterface — `src/Core/Event/FailFastEventInterface.php`

메서드가 없는 마커 인터페이스다. 기본 정책(리스너 예외를 기록하고 계속 진행)이 적합하지 않은 이벤트 — 리스너 실패가 데이터 정합성 문제로 이어지는 결제·포인트류 — 가 이 마커를 구현하면, `EventDispatcher::dispatch()`가 리스너의 일반 예외를 삼키지 않고 호출자에게 다시 던진다(`EventDispatcher.php`의 `$event instanceof FailFastEventInterface` 분기). UI 장식이나 추적처럼 일부 실패해도 요청을 계속해야 하는 이벤트는 구현하지 않는다. 현재 번들 코드에는 이 마커를 구현한 이벤트가 없으며(테스트 픽스처 제외), 확장이 자기 이벤트에 적용하는 용도로 열려 있다.

## 동작 흐름

### 구독자 등록 — Provider boot()

구독자 등록 위치는 확장 Provider의 `boot()`로 고정돼 있다. [14. Extension](14-extension.md)에서 설명하는 확장 로딩 순서 — Package 전체 `register` → 종속 Plugin `register` → 전체 register 완료 후 같은 순서로 `boot` — 때문에, `boot()` 시점에는 모든 확장의 서비스가 컨테이너에 등록 완료된 상태다. 따라서 구독자를 만들 때 다른 확장의 서비스를 안전하게 주입받을 수 있다. 부모 Package가 실패하면 종속 Plugin은 register/boot되지 않으므로, 종속 Plugin의 구독자가 부모 없이 매달리는 상황은 생기지 않는다.

실제 코드 — 종속 Plugin BoardReport의 Provider (`packages/Board/Plugins/BoardReport/BoardReportProvider.php`):

```php
public function boot(DependencyContainer $container, Context $context): void
{
    $eventDispatcher = $container->get(EventDispatcher::class);

    $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
    $eventDispatcher->addSubscriber(new ArticleSubscriber(
        $container->get(BoardReportService::class),
        $container->get(AuthService::class)
    ));
}
```

부모 Package인 Board도 같은 패턴으로 `packages/Board/BoardProvider.php`의 `boot()`에서 검색·메뉴·포인트·마이페이지 등 구독자 9종을 `addSubscriber()`로 등록한다.

### 발행과 결과 회수

발행자는 이벤트 객체를 만들어 dispatch하고, 반환된 이벤트에서 결과를 읽는다. `packages/Board/Controller/Front/BoardController.php`의 게시글 액션 수집이 전형이다.

```php
$actionsEvent = $this->eventDispatcher->dispatch(new ArticleActionsCollectEvent(
```

mutable 이벤트는 구독자가 데이터를 추가·변경·차단하는 통로다. `stopPropagation()`은 첫 응답자가 결정하는 구조(예: `src/Core/Event/Rendering/PageTypeResolveEvent.php`)에서만 제한적으로 쓴다.

## 예제 — 종속 Plugin이 부모 Package의 Event를 구독하기

`packages/Board/Plugins/BoardReport/Subscriber/ArticleSubscriber.php`는 종속 Plugin이 부모 Package의 공식 Event와 Core Event를 함께 구독하는 표준 예다. 신고 기능이 Board의 스킨·컨트롤러를 한 줄도 수정하지 않고 붙는다.

```php
class ArticleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ArticleActionsCollectEvent::class => 'onActionsCollect',   // Board 공식 Event
            FrontFootRenderEvent::class => 'onFrontFoot',              // Core Event
            ArticleViewingEvent::class => 'onArticleViewing',          // Board 공식 Event
            ArticleDeletedEvent::class => 'onArticleDeleted',          // Board 공식 Event
        ];
    }

    public function onActionsCollect(ArticleActionsCollectEvent $event): void
    {
        $this->renderedArticleView = true;

        $event->addAction([
            'label' => '신고',
            'url'   => '/board/report/form?article_id=' . $event->getArticleId(),
            'class' => 'board-view__btn--report',
            'icon'  => 'bi-flag',
        ]);
    }
```

- `onActionsCollect`: 게시글 상세 버튼 영역에 [신고] 액션을 추가한다. 스킨은 전달받은 액션 배열을 그리기만 한다.
- `onArticleViewing`: 블라인드 처리된 글이면 `setBlocked(true)`로 조회를 차단한다 — 사전(-ing) 이벤트의 차단 패턴.
- `onArticleDeleted`: 글 삭제 후 신고 데이터를 정리한다 — 사후(-ed) 이벤트의 후처리 패턴.

## Core 공식 Event 카탈로그

`src/Core/Event/` 아래의 실존 Event 전체다. 용도는 각 파일의 주석에서 확인한 내용이다.

| 영역 | Event | 용도 |
|---|---|---|
| (루트) | `RequestInterceptEvent` | 라우팅 직전 요청 가로채기. `setResponse()` 시 Application이 라우팅을 건너뛰고 해당 Response 반환 — 가장 강한 개입 지점 |
| Auth | `LoginFormRenderingEvent` | 로그인 폼 렌더링 시 추가 HTML 주입 |
| Balance | `BalanceAdjustingEvent` | 잔액 변경 전(트랜잭션 내) 검증·차단 |
| Balance | `BalanceAdjustedEvent` | 잔액 변경 완료 후(readonly) 알림·통계·로깅 |
| Block | `BlockContentFilterEvent` | 블록 아이템 resolve 후, 스킨 렌더 전 아이템 필터링 |
| Block | `BlockContentItemsCollectEvent` | 블록 관리 폼에서 콘텐츠 타입별 아이템 목록 수집 |
| Block | `BlockPageCreatedEvent` | 블록 페이지 생성 후 — 프론트 메뉴 자동 등록 |
| Block | `BlockPageDeletedEvent` | 블록 페이지 삭제 후 — 프론트 메뉴 자동 삭제 |
| Block | `BlockPageRenderingEvent` | 블록 페이지(`/p/코드`) 렌더링 시 추가 HTML 주입 |
| Domain | `DomainCreatedEvent` | 도메인 생성 후 초기 데이터 시딩(기본 메뉴·약관 등) |
| Domain | `DomainUpdatedEvent` | 도메인 수정 후 캐시 무효화·후처리 |
| Domain | `DomainDeletedEvent` | 도메인 삭제 후 관련 데이터 정리 |
| Domain | `DomainFormRenderingEvent` | 도메인 편집 폼에 확장 섹션 주입 |
| Domain | `DomainSettingsLinksEvent` | 도메인 목록에 Package별 설정 바로가기 링크 수집 |
| Member | `RegisterFormRenderingEvent` | 프론트 가입 폼에 필드·스크립트 추가 |
| Member | `MemberRegisterValidatingEvent` | 가입 데이터 추가 검증 — `addError()`로 에러 적재 |
| Member | `MemberRegisterPreparingEvent` | 검증 후·저장 전 데이터 가공, `setPluginData()`로 확장 데이터 격리 |
| Member | `MemberFormRenderingEvent` | 관리자 회원 폼에 UI 섹션 추가 |
| Member | `MemberDataEnrichingEvent` | 회원 상세 조회 시 부가 데이터 첨부(상세 전용) |
| Member | `MemberListQueryEvent` | 확장이 Core 회원 목록을 조회하는 Query Event |
| Member | `MemberLevelListQueryEvent` | 확장이 Core 회원 등급 목록을 조회하는 Query Event |
| Mypage | `MypageSectionBuildingEvent` | Package가 마이페이지 허브·섹션 등록 (액자=Core, 그림=Package) |
| Rendering | `SiteContextReadyEvent` | 세션 시작 후·라우팅 직전, Context의 사이트 이미지·로고 교체 |
| Rendering | `RendererResolveEvent` | ViewResponse 렌더링 직전 커스텀 렌더러 지정 |
| Rendering | `ViewContextCreatedEvent` | ViewContext 생성 직후 자체 ViewHelper 등록 |
| Rendering | `FrontFootRenderEvent` | Footer 출력 후·`</body>` 전 HTML 주입 |
| Rendering | `PageTypeResolveEvent` | 페이지 타입 판별 — 첫 응답자가 `stopPropagation()`으로 결정 |
| Search | `SearchEvent` | 통합 검색 실행 시 각 확장이 자기 검색 결과 추가 |
| Search | `SearchSourceCollectEvent` | 검색 설정에서 각 Package가 제공하는 검색 소스 등록 |
| Storage | `SecureFileAccessEvent` | Core가 모르는 category의 다운로드 권한을 구독자가 `grant()` — 아무도 허용하지 않으면 관리자만 허용 |
| Storage | `SecureFileDownloadedEvent` | 보안 파일 다운로드 완료 직전 발행 |
| Tracking | `PageViewedEvent` | 프론트 페이지 렌더링 완료 통지 — 현재 번들 구독자 없이 서드파티 추적 확장점으로 제공 |

`src/Core/Event/` 밖에도 Core 발행 Event가 있다. `AdminMenuBuildingEvent`(`src/Service/Admin/Event/AdminMenuBuildingEvent.php`), `MemberRegisteredByUserEvent`·`MemberUpdatedBySelfEvent`(`src/Service/Member/Event/`), `MemberNotificationPublishedEvent`(`src/Contract/Notification/`) 등 도메인 서비스나 중립 계약 곁에 두는 Event다. 이벤트 실행 규약과 주요 발행 시점은 `docs/dev-guide/event-system.md`에서 설명한다. 현재 VisitorStats는 `PageViewedEvent`가 아니라 `SiteContextReadyEvent`를 구독한다([26. 통계·트래킹](26-tracking.md)).

## Package의 공식 Event 규약

Package의 공개 표면은 공개 Contract(`Contract/Extension`), `Api/DTO`, 그리고 공식 `Event`뿐이다. Package 내부 Service·Repository·Entity는 공개 API가 아니다. 즉 **Package의 공식 Event는 종속 Plugin이 의존해도 되는 공개 표면**이며, Package는 `Event/` 디렉토리에 두는 것으로 그것을 공식화한다.

레퍼런스인 Board Package는 `packages/Board/Event/`에 19개의 공식 Event를 둔다.

| 대상 | Event |
|---|---|
| 게시글 | `ArticleCreatingEvent`(차단 가능) / `ArticleCreatedEvent`, `ArticleUpdatingEvent` / `ArticleUpdatedEvent`, `ArticleDeletingEvent` / `ArticleDeletedEvent`, `ArticleViewingEvent`(차단 가능) / `ArticleViewedEvent`, `ArticleActionsCollectEvent`(상세 화면 액션 수집) |
| 댓글 | `CommentCreatingEvent`(차단 가능) / `CommentCreatedEvent`, `CommentDeletedEvent` |
| 게시판 설정 | `BoardConfigCreatedEvent`, `BoardConfigDeletedEvent` |
| 파일 | `FileUploadedEvent`, `FileDownloadingEvent`(차단 가능) / `FileDownloadedEvent` |
| 반응 | `ReactionAddedEvent`, `ReactionRemovedEvent` |

명명 관례가 곧 의미다: 진행형(-ing)은 사전 이벤트로 검증·차단이 가능하고, 과거형(-ed)은 완료 후 발행되는 readonly 이벤트로 차단할 수 없다. 발행 지점은 Board의 Service 계층이다(예: `packages/Board/Service/BoardArticleService.php`가 `ArticleViewingEvent`·`ArticleCreatedEvent`·`ArticleDeletedEvent` 등을 dispatch).

종속 Plugin이 부모 Package와 상호작용하는 공식 통로가 이 Event들과 공개 Contract이며, BoardReport가 그 표준 사용례다. Package 구조 전반은 [11. Package](11-package.md), 종속 Plugin 규약은 [12. Plugin](12-plugin.md)을 참조한다.

## Event 호환성 원칙

Event의 안정성 기준은 `docs/compatibility-policy.md`가 진실이다. 요약하면:

- 이름이 `Event`로 끝나거나 `\Event\` 아래에 있는 클래스는 안정 API 영역에 속하며, 종속 Plugin 관점에서는 부모 Package의 공식 `Event\*`가 안정 API다.
- 안정 이벤트의 조건: 실제 Plugin/Package가 사용 중이고, 발행 시점이 명확하며, payload 의미가 도메인 관점에서 안정적이고, Core 내부 구현 세부를 과도하게 노출하지 않는다.
- 아직 문서화되지 않은 이벤트 payload 세부는 내부 API로 취급되어 호환성이 보장되지 않는다.
- 새 이벤트 추가는 Minor 버전, 안정 API 제거는 Major 버전에 해당한다.

대표 안정 이벤트 목록을 포함한 전문은 `docs/compatibility-policy.md`의 "Event 안정성" 절을 본다.

## 관련 문서

- `docs/dev-guide/event-system.md` — Core 발행 지점 전수 조사, 이벤트별 발행 시점·용도·실제 Subscriber 사례
- `docs/compatibility-policy.md` — 안정 API 목록과 Event 안정성 조항
- [11. Package](11-package.md) — Package의 공개 표면과 생명주기
- [12. Plugin](12-plugin.md) — 종속 Plugin이 부모의 공식 Event에 의존하는 구조
- [14. Extension](14-extension.md) — register/boot 순서와 부모-자식 실패 전파
- [15. Public API](15-public-api.md) — 안정 API와 내부 구현의 경계
