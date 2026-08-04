# 이벤트 시스템

Mublo의 이벤트 시스템은 `Plugin`과 `Package`가 Core 코드를 직접 수정하지 않고 기능을 확장하는 기본 메커니즘이다.

이 문서는 **실제 코어 발행 지점(`src/**`)을 전수 조사한 결과**를 기준으로 정리했다.

범위:
- 포함: `src/**` 안에서 실제 `dispatch()`가 호출되는 지점
- 제외: `packages/**`, `plugins/**` 내부에서 자체 발행하는 이벤트

목적:
- 코어가 어떤 시점에 어떤 이벤트를 발행하는지 빠르게 파악
- 확장 개발 시 “어디에 붙어야 하는가”를 명확히 안내
- **명시적 이벤트 발행 지점**을 기준으로 확장하도록 유도

## 핵심 개념

흐름은 단순하다.

```php
Service / Controller / Renderer
    -> new SomeEvent(...)
    -> EventDispatcher::dispatch($event)
    -> Subscriber가 처리
```

구성 요소:
- Dispatcher: [`src/Core/Event/EventDispatcher.php`](../../src/Core/Event/EventDispatcher.php)
- 이벤트 베이스: [`src/Core/Event/AbstractEvent.php`](../../src/Core/Event/AbstractEvent.php)
- 구독 인터페이스: [`src/Core/Event/EventSubscriberInterface.php`](../../src/Core/Event/EventSubscriberInterface.php)

원칙:
- 코어는 **확장 포인트가 필요한 시점**에만 이벤트를 발행한다.
- 구독자는 `Provider.boot()`에서 `addSubscriber()`로 등록한다.
- 이벤트 객체가 mutable이면, 구독자가 데이터 추가/변경/차단을 수행할 수 있다.
- `stopPropagation()`은 정말 첫 응답자 우선 구조일 때만 제한적으로 사용한다.
- 기본 이벤트는 listener 예외를 기록하고 다음 listener를 계속 실행한다.
- 실패 시 요청을 중단해야 하는 중요한 이벤트는 `FailFastEventInterface`를 구현한다.

## 실패 정책

대부분의 이벤트는 best-effort로 동작한다. 예를 들어 UI 조각 삽입, 방문 통계,
부가 데이터 보강은 일부 listener가 실패해도 요청 전체를 중단하지 않는 편이 낫다.

결제, 재고, 포인트, 보안 후처리처럼 listener 실패가 데이터 정합성 문제로 이어지는
이벤트는 `FailFastEventInterface`를 구현한다. 이 마커를 구현한 이벤트는 listener에서
일반 예외가 발생해도 `EventDispatcher`가 예외를 다시 던진다.

```php
use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;

final class PaymentCapturedEvent extends AbstractEvent implements FailFastEventInterface
{
    public function __construct(public readonly string $orderNo) {}
}
```

## 등록 방식

Subscriber는 보통 `Plugin/Package Provider.boot()`에서 등록한다.

```php
public function boot(DependencyContainer $container, Context $context): void
{
    $dispatcher = $container->get(EventDispatcher::class);
    $dispatcher->addSubscriber(new AdminMenuSubscriber());
}
```

기본 형식:

```php
class ExampleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SomeEvent::class => 'onSomeEvent',
            AnotherEvent::class => ['onAnotherEvent', 10],
        ];
    }
}
```

### 상속 계층 구독

구독 이름은 발행된 이벤트의 클래스명뿐 아니라 **부모 클래스명과 인터페이스명**도
받는다. 발행부가 세분화된 서브클래스를 던져도, 구독부는 원하는 넓이만큼 잡으면 된다.

```php
// 발행: MemberService 는 MemberRegisteredByUserEvent,
//       MemberAdminService 는 MemberRegisteredByAdminEvent 를 던진다.

MemberRegisteredEvent::class       => 'onAnyRegistration',   // 모든 가입 경로
MemberRegisteredByUserEvent::class => 'onUserRegistration',  // 사용자 직접 가입만
```

규칙:
- 우선순위는 계층 전체에서 하나로 병합된다. 부모에 건 `priority 100` 이 자식에 건
  `priority 0` 보다 먼저 실행된다. 이름이 더 구체적이라고 앞서지 않는다.
- 우선순위가 같으면 더 구체적인 이름(구상 클래스)에 걸린 쪽이 먼저다.
- 부모와 자식에 같은 리스너를 동시에 걸면 한 번만 실행된다.
- `stopPropagation()` 은 계층을 가로질러 적용된다 — 자식 이름에 걸린 리스너가 멈추면
  부모 이름에 걸린 리스너도 실행되지 않는다.

`getListeners()` / `hasListeners()` 는 계층을 펼치지 않고 해당 이름에 직접 등록된
리스너만 본다.

## 전수조사 요약

코어에서 실제 발행되는 이벤트는 크게 다음 10개 축으로 나뉜다.

1. 애플리케이션 실행 흐름
2. 렌더링과 View 확장
3. 인증과 로그인 UI
4. 회원가입/회원 수정
5. 관리자 회원 UI
6. 도메인 관리와 도메인 설정
7. 블록/페이지 시스템
8. 마이페이지 콘텐츠 확장
9. 검색
10. 보안 파일/다운로드

## 1. 애플리케이션 실행 흐름

발행 파일:
- [`src/Core/App/Application.php`](../../src/Core/App/Application.php)

### 1.1 `SiteContextReadyEvent`

발행 시점:
- 세션 미들웨어 이후
- 확장 로딩 이후
- 라우팅 직전

용도:
- 세션 기반 사이트 컨텍스트 보강
- 로고/사이트 오버라이드 주입
- 도메인별 추가 런타임 상태 반영

특징:
- `Context`, `Request`를 함께 전달
- 확장이 세션 값이나 도메인 문맥을 읽어 `Context`를 보강하기 좋다

### 1.2 `RequestInterceptEvent`

발행 시점:
- `SiteContextReadyEvent` 직후
- 실제 라우터 실행 직전

용도:
- 요청 선차단
- 특정 조건에서 즉시 `Response` 반환
- 사이트 접근 제어, 프리체크, 강제 리다이렉트

특징:
- 이벤트에서 `Response`를 설정하면 코어가 그 응답을 즉시 반환
- 코어 이벤트 중 **가장 강한 개입 지점**이다

### 1.3 `RendererResolveEvent`

발행 시점:
- `ViewResponse` 처리 시
- Front/Admin 기본 렌더러 fallback 전에

용도:
- 특정 패키지/플러그인이 자체 렌더러를 지정
- 기본 렌더링 파이프라인 교체 또는 우회

특징:
- 렌더러를 설정하지 않으면 코어의 `AdminViewRenderer` 또는 `FrontViewRenderer`가 사용된다

## 2. 렌더링과 View 확장

주요 발행 파일:
- [`src/Core/Rendering/FrontViewRenderer.php`](../../src/Core/Rendering/FrontViewRenderer.php)
- [`src/Controller/Front/PageController.php`](../../src/Controller/Front/PageController.php)

### 2.1 `ViewContextCreatedEvent`

발행 시점:
- `FrontViewRenderer`가 `ViewContext`를 초기화한 직후

용도:
- View helper 등록
- 카테고리/포맷/자체 helper 추가

특징:
- 프론트 뷰 헬퍼 확장에 가장 적합한 지점

### 2.2 `FrontFootRenderEvent`

발행 시점:
- 프론트 Footer 출력 직전
- 페이지 최하단 HTML 주입 슬롯

용도:
- 팝업
- 위젯
- 추적 스크립트
- 추가 HTML/JS 주입

특징:
- `Popup`, `Widget` 같은 운영형 플러그인이 이 지점을 사용한다
- 푸터 하단 삽입 슬롯으로 이해하면 된다

### 2.3 `PageViewedEvent`

발행 시점:
- 프론트 페이지 조립이 끝난 뒤
- `Foot` 출력 직전

용도:
- 렌더 완료 후처리 (감사, 외부 시스템 연동 등)

특징:
- URL, 페이지 타입, 회원 ID, IP, UA, Referer를 함께 전달
- **현재 번들 구독자는 없다** — 서버 방문 통계(VisitorStats)는 라우팅 직전의
  `SiteContextReadyEvent` 시점에 수집하고, 외부 픽셀은 클라이언트(MubloTracking.js)가 담당한다.
  이 이벤트는 렌더 완료 시점이 필요한 확장을 위한 열린 확장 포인트다

### 2.4 `PageTypeResolveEvent`

발행 시점:
- 코어가 기본 페이지 타입(`index`, `auth`, `member`, `search`)으로 판별하지 못한 뒤

용도:
- Package/Plugin이 자신의 view path에 맞는 페이지 타입을 결정

특징:
- `PageViewedEvent`의 `pageType` 품질을 보강하기 위한 보조 이벤트다

### 2.5 `BlockPageRenderingEvent`

발행 파일:
- [`src/Controller/Front/PageController.php`](../../src/Controller/Front/PageController.php)

발행 시점:
- 블록 페이지 본문 HTML을 렌더링한 뒤
- 응답용 데이터 조립 직전

용도:
- 페이지 하단 보조 HTML 주입
- 페이지별 부가 정보 출력

특징:
- 블록 페이지 전용 확장점이다

## 3. 인증과 로그인 UI

주요 발행 파일:
- [`src/Service/Auth/AuthService.php`](../../src/Service/Auth/AuthService.php)
- [`src/Controller/Front/AuthController.php`](../../src/Controller/Front/AuthController.php)
- [`src/Controller/Front/MemberController.php`](../../src/Controller/Front/MemberController.php)
- [`src/Core/Block/Renderer/OutloginRenderer.php`](../../src/Core/Block/Renderer/OutloginRenderer.php)

### 3.1 `MemberLoggedInEvent`

발행 시점:
- 일반 로그인 성공 직후
- `loginByMember()` 기반 로그인 직후

용도:
- 로그인 후처리
- 로그인 이력 적재
- 외부 계정 연결 후속 처리

특징:
- `AuthService`에서만 발행되는 명확한 인증 완료 이벤트다

### 3.2 `LoginFormRenderingEvent`

발행 시점:
- 프론트 로그인 화면 렌더링 직전
- 회원가입 약관 동의 화면에서 로그인 위젯 표시 전
- `OutloginRenderer`가 비로그인 상태 위젯을 그리기 직전

용도:
- 소셜 로그인 버튼 주입
- 비회원 주문 버튼
- 로그인 폼 부가 HTML 삽입

특징:
- 실제 사용 빈도가 높은 UI 확장 이벤트
- HTML 정렬/스크립트 삽입 패턴이 이벤트 객체에 이미 담겨 있다

## 4. 회원가입과 회원 수정

주요 발행 파일:
- [`src/Service/Member/MemberService.php`](../../src/Service/Member/MemberService.php)
- [`src/Controller/Front/MemberController.php`](../../src/Controller/Front/MemberController.php)

### 4.1 `RegisterFormRenderingEvent`

발행 시점:
- 프론트 회원가입 정보 입력 화면 렌더링 직전

용도:
- 플러그인 섹션 추가
- 스크립트 추가
- 회원가입 폼 보강

### 4.2 `MemberRegisterValidatingEvent`

발행 시점:
- 프론트 회원가입 제출 직후
- `MemberService::register()` 호출 전

용도:
- 플러그인 독자 검증 추가
- 추가 에러 메시지 적재

특징:
- 컨트롤러 단계 검증 확장점

### 4.3 `MemberRegisterPreparingEvent`

발행 시점:
- `MemberService::register()` 내부
- DB 저장 직전

용도:
- 플러그인 데이터 가공
- 플러그인별 임시 데이터 수집
- 후속 `MemberRegisteredByUserEvent`에 전달할 plugin payload 구성

특징:
- 회원가입 파이프라인에서 가장 중요한 확장 지점 중 하나

### 4.4 `MemberRegisteredByUserEvent`

발행 시점:
- 회원 생성 및 추가 필드/약관 저장 완료 후

용도:
- 가입 축하 포인트
- 부가 엔티티 생성
- 연동 데이터 저장

특징:
- `pluginData`를 실어 보낼 수 있다

### 4.5 `MemberUpdatedBySelfEvent`

발행 시점:
- 본인 정보 수정 완료 후

용도:
- 프로필 변경 후처리
- 특정 필드 변경 반응

## 5. 관리자 회원 UI와 관리자 회원 작업

주요 발행 파일:
- [`src/Controller/Admin/MemberController.php`](../../src/Controller/Admin/MemberController.php)
- [`src/Service/Member/MemberAdminService.php`](../../src/Service/Member/MemberAdminService.php)

### 5.1 `MemberFormRenderingEvent`

발행 시점:
- 관리자 회원 생성 폼 렌더링 직전
- 관리자 회원 수정 폼 렌더링 직전

용도:
- 플러그인 섹션 추가
- 추가 폼 스크립트 주입

### 5.2 `MemberDataEnrichingEvent`

발행 시점:
- 관리자 회원 상세 데이터를 화면에 전달하기 직전

용도:
- 회원 추가 메타데이터 보강
- 플러그인 부가 정보 추가

### 5.3 `MemberRegisteredByAdminEvent`

발행 시점:
- 관리자가 회원 생성 완료 후

용도:
- 관리자 등록 후속 처리
- 레벨/정책 기반 자동 작업

### 5.4 `MemberUpdatedByAdminEvent`

발행 시점:
- 관리자가 회원 수정 완료 후

용도:
- 등급 변경 반응
- 상태 변경 반응
- 관리 UI 후속 작업

## 6. 도메인 관리와 도메인 설정

주요 발행 파일:
- [`src/Service/Domain/DomainService.php`](../../src/Service/Domain/DomainService.php)
- [`src/Controller/Admin/DomainsController.php`](../../src/Controller/Admin/DomainsController.php)
- [`src/Service/Domain/DomainSettingsService.php`](../../src/Service/Domain/DomainSettingsService.php)

### 6.1 `DomainCreatedEvent`

발행 시점:
- 도메인 생성, `domain_group` 생성, 소유자 도메인 연결 완료 후

용도:
- 기본 메뉴 시딩
- 기본 정책 시딩
- 패키지/플러그인 초기 데이터 구성

### 6.2 `DomainUpdatedEvent`

발행 시점:
- 도메인 수정 완료 후

용도:
- 관련 데이터 동기화
- 캐시/연동 후처리

### 6.3 `DomainDeletedEvent`

발행 시점:
- 도메인 삭제 완료 후

용도:
- 도메인 종속 데이터 정리
- 패키지별 정리 작업

### 6.4 `DomainSettingsLinksEvent`

발행 시점:
- 관리자 도메인 목록 화면에서 설정 링크를 수집할 때

용도:
- 패키지별 도메인 설정 링크 노출

특징:
- “도메인별 설정 이동 경로”를 확장이 등록하는 구조다

### 6.5 `DomainFormRenderingEvent`

발행 시점:
- 도메인 생성 폼 렌더링 직전
- 도메인 수정 폼 렌더링 직전

용도:
- 도메인 연동성 플러그인이 도메인 폼에 자체 섹션을 추가

### 6.6 `SearchSourceCollectEvent`

발행 시점:
- 도메인 설정에서 검색 소스 목록을 읽을 때
- 검색 소스 입력값을 sanitize할 때

용도:
- Package가 검색 가능 소스 목록을 등록

특징:
- 도메인 설정과 검색 시스템을 느슨하게 연결하는 핵심 이벤트다

## 7. 블록/페이지 시스템

주요 발행 파일:
- [`src/Service/Block/BlockPageService.php`](../../src/Service/Block/BlockPageService.php)
- [`src/Controller/Admin/BlockRowController.php`](../../src/Controller/Admin/BlockRowController.php)

### 7.1 `BlockPageCreatedEvent`

발행 시점:
- 블록 페이지 생성 완료 후

용도:
- 메뉴 자동 등록
- 관련 보조 데이터 생성

### 7.2 `BlockPageDeletedEvent`

발행 시점:
- 블록 페이지 삭제 또는 소프트 삭제 완료 후

용도:
- 메뉴 정리
- 관련 링크 정리

### 7.3 `BlockContentItemsCollectEvent`

발행 시점:
- 블록 콘텐츠 타입별 아이템 목록이 필요할 때

용도:
- 패키지/플러그인이 자신이 제공하는 콘텐츠 후보 목록을 공급

특징:
- 블록 편집기에서 “무엇을 선택할 수 있는가”를 확장이 공급하는 구조다

## 8. 마이페이지 콘텐츠 확장

발행 파일:
- [`src/Service/Mypage/MypageSectionRegistry.php`](../../src/Service/Mypage/MypageSectionRegistry.php)

### 8.1 `MypageSectionBuildingEvent`

발행 시점:
- 마이페이지 섹션 구성 시 (요청당 1회, `MypageSectionRegistry`가 발행)

용도:
- 패키지가 마이페이지 섹션(내가 쓴 글/댓글 등)을 공급
- 목록/페이지네이션을 외부 subscriber가 채움

특징:
- 코어가 게시글/댓글 저장소를 직접 몰라도 되도록 만든 디커플링 포인트다

## 9. 검색

발행 파일:
- [`src/Service/Search/SearchService.php`](../../src/Service/Search/SearchService.php)

### 9.1 `SearchEvent`

발행 시점:
- 통합 검색 실행 시

용도:
- 각 검색 소스가 결과 그룹을 추가
- 게시판, 쇼핑, 렌탈 같은 패키지가 검색 결과를 제공

특징:
- 코어가 검색 소스 구현을 직접 알지 않는다

## 10. 포인트/잔액

발행 파일:
- [`src/Service/Balance/BalanceManager.php`](../../src/Service/Balance/BalanceManager.php)

### 10.1 `BalanceAdjustingEvent`

발행 시점:
- 원장 기록 직전
- 트랜잭션 내부

용도:
- 잔액 조정 차단
- 사전 검증 추가

특징:
- `block()` 성격의 제어가 가능한 사전 이벤트

### 10.2 `BalanceAdjustedEvent`

발행 시점:
- 트랜잭션 커밋 완료 후

용도:
- 지급/차감 완료 후 후속 작업
- 알림, 적립 후처리, 외부 동기화

## 11. 관리자 메뉴

발행 파일:
- [`src/Service/Admin/AdminMenuService.php`](../../src/Service/Admin/AdminMenuService.php)

### 11.1 `AdminMenuBuildingEvent`

발행 시점:
- 코어 메뉴를 올린 뒤
- Package/Plugin 메뉴를 통합할 때

용도:
- 관리자 메뉴 그룹/서브메뉴 추가
- 기존 메뉴 앞/뒤 삽입

특징:
- 현재 확장 생태계에서 가장 많이 쓰는 이벤트 중 하나다

## 12. 보안 파일/다운로드

발행 파일:
- [`src/Controller/Api/DownloadController.php`](../../src/Controller/Api/DownloadController.php)

### 12.1 `SecureFileAccessEvent`

발행 시점:
- 토큰 검증과 도메인 검증 후
- 코어 기본 카테고리(`member-fields`, `autoform`)에 해당하지 않을 때

용도:
- 카테고리별 다운로드 권한 판단을 확장에 위임

특징:
- 아무 subscriber도 grant하지 않으면 안전 기본값으로 관리자만 허용한다

### 12.2 `SecureFileDownloadedEvent`

발행 시점:
- 최종 권한 검증 통과 후
- 실제 파일 응답 직전

용도:
- 다운로드 로그
- 후속 이력 적재

## 발행 지점 목록

아래는 `src/**` 기준 실제 발행 파일과 이벤트를 빠르게 찾기 위한 인덱스다.

| 발행 파일 | 이벤트 |
|---|---|
| [`src/Core/App/Application.php`](../../src/Core/App/Application.php) | `SiteContextReadyEvent`, `RequestInterceptEvent`, `RendererResolveEvent` |
| [`src/Core/Rendering/FrontViewRenderer.php`](../../src/Core/Rendering/FrontViewRenderer.php) | `ViewContextCreatedEvent`, `FrontFootRenderEvent`, `PageViewedEvent`, `PageTypeResolveEvent` |
| [`src/Core/Block/Renderer/OutloginRenderer.php`](../../src/Core/Block/Renderer/OutloginRenderer.php) | `LoginFormRenderingEvent` |
| [`src/Controller/Front/AuthController.php`](../../src/Controller/Front/AuthController.php) | `LoginFormRenderingEvent` |
| [`src/Controller/Front/MemberController.php`](../../src/Controller/Front/MemberController.php) | `LoginFormRenderingEvent`, `RegisterFormRenderingEvent`, `MemberRegisterValidatingEvent` |
| [`src/Service/Mypage/MypageSectionRegistry.php`](../../src/Service/Mypage/MypageSectionRegistry.php) | `MypageSectionBuildingEvent` |
| [`src/Controller/Front/PageController.php`](../../src/Controller/Front/PageController.php) | `BlockPageRenderingEvent` |
| [`src/Controller/Admin/DomainsController.php`](../../src/Controller/Admin/DomainsController.php) | `DomainSettingsLinksEvent`, `DomainFormRenderingEvent` |
| [`src/Controller/Admin/MemberController.php`](../../src/Controller/Admin/MemberController.php) | `MemberFormRenderingEvent`, `MemberDataEnrichingEvent` |
| [`src/Controller/Admin/BlockRowController.php`](../../src/Controller/Admin/BlockRowController.php) | `BlockContentItemsCollectEvent` |
| [`src/Controller/Api/DownloadController.php`](../../src/Controller/Api/DownloadController.php) | `SecureFileAccessEvent`, `SecureFileDownloadedEvent` |
| [`src/Service/Auth/AuthService.php`](../../src/Service/Auth/AuthService.php) | `MemberLoggedInEvent` |
| [`src/Service/Member/MemberService.php`](../../src/Service/Member/MemberService.php) | `MemberRegisterPreparingEvent`, `MemberRegisteredByUserEvent`, `MemberUpdatedBySelfEvent`, `MemberWithdrawingEvent`(차단 가능), `MemberWithdrawnEvent` |
| [`src/Service/Member/MemberAdminService.php`](../../src/Service/Member/MemberAdminService.php) | `MemberRegisteredByAdminEvent`, `MemberUpdatedByAdminEvent`, `MemberDeletingEvent`(차단 가능), `MemberDeletedEvent` |
| [`src/Service/Domain/DomainService.php`](../../src/Service/Domain/DomainService.php) | `DomainCreatedEvent`, `DomainUpdatedEvent`, `DomainDeletedEvent` |
| [`src/Service/Domain/DomainSettingsService.php`](../../src/Service/Domain/DomainSettingsService.php) | `SearchSourceCollectEvent` |
| [`src/Service/Block/BlockPageService.php`](../../src/Service/Block/BlockPageService.php) | `BlockPageCreatedEvent`, `BlockPageDeletedEvent` |
| [`src/Service/Balance/BalanceManager.php`](../../src/Service/Balance/BalanceManager.php) | `BalanceAdjustingEvent`, `BalanceAdjustedEvent` |
| [`src/Service/Search/SearchService.php`](../../src/Service/Search/SearchService.php) | `SearchEvent` |
| [`src/Service/Admin/AdminMenuService.php`](../../src/Service/Admin/AdminMenuService.php) | `AdminMenuBuildingEvent` |

## 확장 개발 우선 추천 이벤트

새 확장을 만들 때 가장 먼저 검토할 이벤트는 보통 아래다.

- 관리자 메뉴 추가: `AdminMenuBuildingEvent`
- 로그인 UI 확장: `LoginFormRenderingEvent`
- 회원가입 UI 확장: `RegisterFormRenderingEvent`
- 회원가입 검증/데이터 준비: `MemberRegisterValidatingEvent`, `MemberRegisterPreparingEvent`
- 도메인 생성 후 기본 데이터 시딩: `DomainCreatedEvent`
- 마이페이지 탭 공급: `MypageSectionBuildingEvent`
- 검색 결과 공급: `SearchEvent`, `SearchSourceCollectEvent`
- 프론트 하단 UI 삽입: `FrontFootRenderEvent`
- 다운로드 권한 처리: `SecureFileAccessEvent`

## 실제 Subscriber 사례

아래는 코어 이벤트에 실제로 어떤 확장이 붙는지 보여 주는 대표 사례다. 모든 subscriber를 완전 목록화한 것은 아니지만, 현재 코드베이스에서 반복적으로 쓰이는 주요 연결만 추렸다.

### `AdminMenuBuildingEvent`

대표 subscriber:
- [`packages/Board/Subscriber/AdminMenuSubscriber.php`](../../packages/Board/Subscriber/AdminMenuSubscriber.php)
- [`packages/Shop/AdminMenuSubscriber.php`](../../packages/Shop/AdminMenuSubscriber.php)
- [`plugins/Banner/AdminMenuSubscriber.php`](../../plugins/Banner/AdminMenuSubscriber.php)
- [`plugins/Popup/AdminMenuSubscriber.php`](../../plugins/Popup/AdminMenuSubscriber.php)
- [`plugins/VisitorStats/AdminMenuSubscriber.php`](../../plugins/VisitorStats/AdminMenuSubscriber.php)
- [`plugins/SnsLogin/AdminMenuSubscriber.php`](../../plugins/SnsLogin/AdminMenuSubscriber.php)

의미:
- 관리자 메뉴는 코어 하드코딩만으로 끝나지 않고, 실제로 거의 모든 확장이 이 이벤트를 통해 통합된다.

### `LoginFormRenderingEvent`

대표 subscriber:
- [`plugins/SnsLogin/Subscriber/LoginFormSubscriber.php`](../../plugins/SnsLogin/Subscriber/LoginFormSubscriber.php)
- [`packages/Shop/EventSubscriber/LoginFormSubscriber.php`](../../packages/Shop/EventSubscriber/LoginFormSubscriber.php)

의미:
- 로그인 UI 확장은 이미 검증된 패턴이다.

### `DomainCreatedEvent`

대표 subscriber:
- [`packages/Board/Subscriber/DomainEventSubscriber.php`](../../packages/Board/Subscriber/DomainEventSubscriber.php)
- [`packages/Shop/EventSubscriber/DomainEventSubscriber.php`](../../packages/Shop/EventSubscriber/DomainEventSubscriber.php)
- [`src/Core/Event/Domain/DomainEventSubscriber.php`](../../src/Core/Event/Domain/DomainEventSubscriber.php)

의미:
- 신규 도메인 생성 시 기본 데이터/메뉴/설정 시딩의 중심 이벤트다.

### `SearchSourceCollectEvent`, `SearchEvent`

대표 subscriber:
- [`packages/Board/Subscriber/BoardSearchSubscriber.php`](../../packages/Board/Subscriber/BoardSearchSubscriber.php)
- [`packages/Shop/EventSubscriber/ShopSearchSubscriber.php`](../../packages/Shop/EventSubscriber/ShopSearchSubscriber.php)

의미:
- 검색 시스템은 이벤트 기반 디커플링이 실제로 잘 작동하는 대표 사례다.

### `FrontFootRenderEvent`

대표 subscriber:
- [`plugins/Popup/FrontRenderSubscriber.php`](../../plugins/Popup/FrontRenderSubscriber.php)
- [`plugins/Widget/FrontRenderSubscriber.php`](../../plugins/Widget/FrontRenderSubscriber.php)

의미:
- 운영형 UI 플러그인이 동일한 슬롯을 공유하는 방식이다.

### `MypageSectionBuildingEvent`

대표 subscriber:
- [`packages/Board/Subscriber/MypageSectionSubscriber.php`](../../packages/Board/Subscriber/MypageSectionSubscriber.php)
- [`packages/Shop/EventSubscriber/MypageSectionSubscriber.php`](../../packages/Shop/EventSubscriber/MypageSectionSubscriber.php)

의미:
- 마이페이지 콘텐츠를 코어가 직접 구현하지 않고 패키지가 공급하는 구조다.

### `BlockPageCreatedEvent`

대표 subscriber:
- [`src/Subscriber/BlockPageMenuSubscriber.php`](../../src/Subscriber/BlockPageMenuSubscriber.php)

의미:
- 코어 내부에서도 이벤트를 활용해 블록 페이지와 메뉴를 느슨하게 연결한다.

## 안정성 분류

확장 개발 관점에서 코어 이벤트를 두 부류로 나눠 보면 이해가 쉽다.

### 1. 안정 이벤트

확장 API처럼 봐도 되는 이벤트다. 플랫폼 성격을 설명하는 데도 중요하고, 향후에도 유지될 가능성이 높다.

- `AdminMenuBuildingEvent`
- `LoginFormRenderingEvent`
- `RegisterFormRenderingEvent`
- `MemberRegisterPreparingEvent`
- `MemberRegisteredByUserEvent`
- `MemberRegisteredByAdminEvent`
- `MemberUpdatedBySelfEvent`
- `MemberUpdatedByAdminEvent`
- `DomainCreatedEvent`
- `DomainUpdatedEvent`
- `DomainDeletedEvent`
- `DomainSettingsLinksEvent`
- `SearchSourceCollectEvent`
- `SearchEvent`
- `MypageSectionBuildingEvent`
- `FrontFootRenderEvent`
- `SecureFileAccessEvent`
- `SecureFileDownloadedEvent`
- `BlockPageCreatedEvent`
- `BlockPageDeletedEvent`

기준:
- 이미 실제 패키지/플러그인이 붙어 있다
- 도메인/회원/메뉴/검색/렌더링 같은 핵심 확장 포인트다
- 기능 동결 이후에도 구조적 필요가 유지된다

### 2. 내부 성격이 강한 이벤트

사용은 가능하지만, 코어 내부 흐름과 더 밀접하다. 확장 API라고 보기보다는 특정 목적이 뚜렷한 이벤트다.

- `SiteContextReadyEvent`
- `RequestInterceptEvent`
- `RendererResolveEvent`
- `ViewContextCreatedEvent`
- `PageTypeResolveEvent`
- `BlockContentItemsCollectEvent`
- `BlockPageRenderingEvent`
- `MemberRegisterValidatingEvent`
- `MemberDataEnrichingEvent`
- `BalanceAdjustingEvent`
- `BalanceAdjustedEvent`
- `PageViewedEvent`

기준:
- 발행 시점이 렌더링/요청 처리 내부와 가깝다
- payload가 특정 화면/흐름에 강하게 묶여 있다
- 잘못 쓰면 코어 동작에 대한 의존도가 높아질 수 있다

## 새 이벤트를 추가하기 전에

코어는 기능 동결에 가깝게 운영하므로, 새 이벤트 추가는 신중해야 한다.

체크 기준:
- 기존 이벤트로 해결할 수 없는가
- 실제로 둘 이상의 확장이 재사용할 가능성이 있는가
- 코어의 내부 구현 세부를 외부에 노출하지 않는가
- payload가 명확한 클래스/의미를 갖는가

추천:
- 새 이벤트보다 기존 안정 이벤트 활용 우선
- UI 삽입은 `LoginFormRenderingEvent`, `FrontFootRenderEvent`, `MemberFormRenderingEvent` 우선 검토
- 도메인 초기화는 `DomainCreatedEvent` 우선 검토
- 데이터 공급형 확장은 `SearchEvent`, `MypageSectionBuildingEvent`, `BlockContentItemsCollectEvent` 우선 검토

## 현재 이벤트가 충분한가

현재 코드베이스 기준으로는 **대체로 충분하다**고 본다.

판단 근거:
- 관리자 메뉴 확장: `AdminMenuBuildingEvent`
- 로그인/회원가입 UI 확장: `LoginFormRenderingEvent`, `RegisterFormRenderingEvent`, `MemberFormRenderingEvent`
- 회원가입/회원 후처리: `MemberRegisterPreparingEvent`, `MemberRegisteredBy*`, `MemberUpdatedBy*`
- 도메인 초기화/정리: `DomainCreatedEvent`, `DomainUpdatedEvent`, `DomainDeletedEvent`
- 검색 소스와 결과 공급: `SearchSourceCollectEvent`, `SearchEvent`
- 마이페이지 콘텐츠 공급: `MypageSectionBuildingEvent`
- 프론트 하단 UI 주입: `FrontFootRenderEvent`
- 다운로드 권한 위임: `SecureFileAccessEvent`
- 블록 페이지 생성/삭제 후처리: `BlockPageCreatedEvent`, `BlockPageDeletedEvent`

즉, 현재 플랫폼이 실제로 사용하는 확장 요구는 이미 대부분 커버된다.

### 추가 이벤트가 굳이 필요하지 않은 이유

1. 이미 실제 패키지/플러그인이 붙는 안정 이벤트가 충분히 있다.
2. 신규 이벤트보다 기존 이벤트 payload를 활용하는 편이 결합도를 낮춘다.
3. 코어를 기능 동결에 가깝게 운영할수록, 이벤트 수를 늘리는 것보다 의미를 명확히 유지하는 것이 중요하다.

### 예외적으로 추가를 검토할 수 있는 경우

아래 조건을 모두 만족할 때만 새 이벤트를 검토한다.

- 둘 이상의 확장이 같은 지점을 반복적으로 필요로 한다
- 기존 안정 이벤트로 우회가 어렵다
- 이벤트 이름과 payload가 도메인 의미를 명확히 갖는다
- 코어 내부 구현 세부를 외부에 과도하게 노출하지 않는다

현재 시점의 결론:
- 코어 이벤트는 부족하다고 보기 어렵다
- 특별히 추가할 이벤트가 없어도 구조적으로 문제 없다
- 앞으로의 우선순위는 **새 이벤트 추가**보다 **기존 이벤트 문서화와 사용 일관성 유지**다

## 설계 판단 기준

새 확장 포인트를 추가할 때는 아래 기준을 따른다.

좋은 후보:
- 코어가 특정 구현을 몰라도 되는 경우
- 패키지/플러그인별 정책이 달라지는 경우
- UI나 후처리를 외부가 붙일 수 있어야 하는 경우

나쁜 후보:
- 단순한 내부 메서드 분해로 해결 가능한 경우
- payload가 불명확한 경우
- 이벤트를 추가해도 실제 구독할 주체가 없는 경우

## 현재 철학

Mublo의 이벤트 시스템은 이벤트 클래스와 명시적인 발행 지점을 기준으로 동작한다.

차이:
- 문자열 훅명이 아니라 **명시적 이벤트 클래스**를 사용
- 발행 지점이 코어 코드에 드러난다
- Subscriber 등록 위치가 `Provider.boot()`로 고정돼 있다
- 확장 방식이 “어디서든 끼어드는 훅”보다 “명확한 확장 포인트를 구독하는 이벤트”에 가깝다

즉, Mublo는 **이벤트 기반 확장**을 기본 철학으로 유지한다.

---

[< 개발자 가이드로](README.md)
