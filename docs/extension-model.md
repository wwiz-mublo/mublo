# 확장 모델

Mublo의 확장 모델은 Plugin, Package, Event, Contract, Block으로 구성됩니다.

## 전체 그림

```text
                 Core
                  |
      ┌───────────┼───────────┐
      |           |           |
    Event      Contract     Block
      |           |           |
   Plugin      Package    Page Builder
```

Core는 직접 기능을 많이 품지 않고, 확장이 붙을 수 있는 규칙과 실행 지점을 제공합니다.

## Plugin

Plugin은 Core나 다른 기능 위에 붙는 작은 확장입니다.

적합한 기능:

- 배너
- 팝업
- FAQ
- 소셜 로그인
- 포인트 지급 정책
- 방문자 통계
- 알림/SMS/메일 게이트웨이

Plugin은 보통 다음 방식으로 Core에 붙습니다.

- 관리자 메뉴 추가
- 프론트 출력 슬롯에 HTML 삽입
- 회원가입/로그인/주문 같은 이벤트에 반응
- Contract 구현체 제공
- 블록 콘텐츠 타입 등록

## Package

Package는 자체 Controller, Service, Repository, Entity, View, migration을 가진 독립 애플리케이션입니다.

적합한 기능:

- 게시판
- 쇼핑몰
- 자체 Controller·Service·Repository와 데이터 구조가 필요한 독립 업무 도메인

Package는 독립 업무 영역을 가지지만, Core와의 연결은 Provider, Event, Contract, Block 규칙을 따릅니다.

## Provider 생명주기

모든 Plugin/Package는 Provider를 진입점으로 사용합니다.

```php
interface ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void;
    public function boot(DependencyContainer $container, Context $context): void;
}
```

| 단계 | 시점 | 주 용도 |
|------|------|---------|
| `register()` | Context 생성 전 | 서비스, Repository, Renderer 등록 |
| `boot()` | Context 생성 후 | 이벤트 구독, 블록 등록, Contract 바인딩 |

## Event를 쓰는 경우

Event는 "어떤 일이 일어났다"를 알리는 push 방식입니다.

좋은 사용 예:

- 회원가입 완료 후 포인트 지급
- 관리자 메뉴에 플러그인 메뉴 추가
- 로그인 폼에 소셜 로그인 버튼 삽입
- 주문 상태 변경 후 알림 발송
- 검색 실행 시 패키지별 결과 추가

## Contract를 쓰는 경우

Contract는 "필요한 기능을 호출하고 결과를 받는" pull 방식입니다.

좋은 사용 예:

- 결제 게이트웨이 선택과 결제 처리
- FAQ 데이터 조회
- SMS/알림톡/이메일 발송
- 카테고리 트리 공급
- CDN 캐시 퍼지 구현체 호출

## Block을 쓰는 경우

Block은 기능을 운영자가 조합할 수 있는 화면 구성 요소로 드러낼 때 사용합니다.

좋은 사용 예:

- 최신 게시글 블록
- 상품 목록 블록
- 배너 블록
- 리뷰 자동 노출 블록
- 설문 블록

블록은 렌더러와 스킨만 있는 것이 아니라, 선택 가능한 출력 아이템을 공급할 수 있습니다.

## 선택 기준

| 상황 | 선택 |
|------|------|
| 독립 업무 영역이다 | Package |
| 작은 부가 기능이다 | Plugin |
| 일이 발생한 뒤 반응한다 | Event |
| 값을 받아야 한다 | Contract |
| 운영자가 화면에서 조합해야 한다 | Block |
| Core 실행 규칙이다 | Core |
