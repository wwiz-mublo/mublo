# 33. Reference Packages

Board와 BoardReport는 Mublo Package Platform 규약의 공식 실증이다. 이 장은 "규약이 실제 코드에서 어떤 모습인가"를 해설한다. 새 Package Platform이나 종속 Plugin을 만들 때 이 두 코드베이스를 복제 가능한 출발점으로 삼으라.

## Board — Package Platform의 실물

`packages/Board/`는 게시판 기능이자, 종속 Plugin을 수용하는 플랫폼이다.

### 플랫폼 선언

```php
// packages/Board/BoardProvider.php
class BoardProvider implements ExtensionProviderInterface, InstallableExtensionInterface, PluginHostInterface
{
    use PluginHostTrait;   // packages/Board/Plugins/{Name}/ 규약으로 종속 Plugin 발견
```

`PluginHostInterface` 구현이 곧 "이 Package는 Plugin을 수용한다"는 선언이다. 표준 디렉토리 규약이면 Trait 한 줄로 끝난다.

### 공개 표면

```text
packages/Board/
├── Contract/Extension/
│   ├── BoardExtensionApiInterface.php      # 공개 API 진입점
│   ├── BoardArticleReaderInterface.php     # 게시글 조회 (도메인·전역 게시판 정책 적용)
│   └── BoardArticleCommandInterface.php    # 게시글 변경 명령
├── Api/
│   ├── BoardExtensionApi.php               # @internal 구현체
│   ├── BoardArticleReader.php              # @internal
│   ├── BoardArticleCommand.php             # @internal
│   └── DTO/ArticleSnapshot.php             # readonly 값 객체
└── Event/                                  # 공식 Event 19종
```

설계 포인트:

- **Reader가 정책을 내장한다.** `findAccessibleById(articleId, domainId)`는 현재 도메인 소유 글 또는 전역 게시판 글만 반환한다. Plugin이 도메인 격리를 직접 구현하지 않아도 된다.
- **읽기 권한과 쓰기 권한은 별개다.** 전역 게시판 글이 조회된다는 것은 읽고 신고할 수 있다는 뜻이지 변경 권한이 아니다. `BoardArticleCommandInterface::delete()`는 내부에서 Board의 기존 권한 검증을 다시 통과한다 (`packages/Board/README.md`).
- **Snapshot은 필요한 것만 담는다.** `ArticleSnapshot`은 articleId·domainId·boardId·title — Plugin이 실제로 쓰는 필드만. 내부 Entity의 영속 구조는 노출되지 않는다.

### 공식 Event

Board는 게시글·댓글 생명주기의 개입 지점을 Event로 공개한다. 전체 표(발생 시점·용도)는 `packages/Board/README.md`가 진실이다. 대표:

| Event | 용도 |
|---|---|
| `ArticleActionsCollectEvent` | 게시글 상세의 동작 버튼 수집 — 신고·북마크 등 추가 |
| `ArticleViewingEvent` | 노출 직전 개입 — 블라인드·접근 정책 |
| `ArticleDeletedEvent` | 삭제 후 Plugin 데이터 정리 |

## BoardReport — 종속 Plugin의 레퍼런스

`packages/Board/Plugins/BoardReport/`는 제3자 Board Plugin이 따라야 할 기준 구현이다.

### 배치와 Manifest

```text
위치:       packages/Board/Plugins/BoardReport/
활성 키:    Board/BoardReport
Migration:  source=plugin, name=Board/BoardReport
```

`manifest.json`은 `parent: "Board"`와 `requires["package:Board"]` 버전 범위, capability 선언(v1 호환 확장 필드)을 포함한다. [13. Manifest](13-manifest.md) 참조.

### 부모 의존 — 공개 Contract 하나로 수렴

```php
// BoardReportProvider.php — 부모 접근점은 BoardExtensionApiInterface 하나다
$container->singleton(BoardReportService::class, fn($c) =>
    new BoardReportService(
        $c->get(BoardReportRepository::class),        // 자기 데이터
        $c->get(BoardExtensionApiInterface::class)    // 부모의 공개 API
    )
);
```

```php
// BoardReportService.php — 도메인 격리는 Reader가 처리한다
$article = $this->board->articles()->findAccessibleById($articleId, $domainId);
if ($article === null) {
    return Result::failure('게시글을 찾을 수 없습니다.');
}
```

Board의 `Service`·`Repository`·`Entity`·`Helper`는 어디에서도 import하지 않는다. `php tools/check-extension-api.php`가 이를 검사한다.

### 부모 개입 — 공식 Event 구독

`Subscriber/ArticleSubscriber.php`는 `ArticleActionsCollectEvent`(신고 버튼 추가), `ArticleViewingEvent`(블라인드 적용), `ArticleDeletedEvent`(신고 데이터 정리)를 구독한다. 부모 코드를 한 줄도 수정하지 않고 게시글 화면과 생명주기에 개입하는 표준 경로다.

### 자기 데이터의 소유

- 신고·블라인드 데이터는 자체 테이블에, 자체 `database/migrations/`로 관리한다.
- 신고 데이터는 조치를 실행한 현재 도메인에 귀속된다 — 전역 게시판 글에 대한 신고도 신고한 도메인의 데이터다.

### 테스트

부모 경계·격리 시나리오가 테스트로 고정돼 있다: `packages/Board/tests/Unit/Plugin/BoardReportServiceTest.php`, `packages/Board/tests/Unit/Api/BoardExtensionApiTest.php`, 그리고 확장 런타임 순서·격리는 `tests/Unit/Core/Extension/ExtensionManagerLifecycleTest.php`.

## 새 확장의 출발점으로 쓰기

- **새 Board Plugin**: BoardReport를 복제한다. 절차는 `packages/Board/Plugins/BoardReport/README.md`와 [30. Plugin Guide](30-plugin-guide.md).
- **새 Package Platform**: Board의 구조(PluginHostTrait + Contract/Api/Event 3층)를 참고한다. 절차는 [29. Package Guide](29-package-guide.md) 5절.

## 번들 확장 카탈로그

이 저장소에 포함된 배포 확장의 전수 목록이다. **각 확장의 기능 상세는 해당 확장의 README와 manifest가 진실이다** — 이 책은 규약을 다루고, 확장은 자신을 스스로 문서화한다. "규약 사례" 열은 그 확장이 이 책의 어느 규약을 실증하는지를 가리킨다.

### Package (2)

| Package | 설명 (manifest) | 규약 사례 |
|---|---|---|
| Board | 게시판·커뮤니티 기본 패키지 | 이 장 전체 — Package Platform 레퍼런스 |
| Shop | 상품·장바구니·주문·결제 쇼핑몰 패키지 | 결제 Contract 소비([16](16-contract-catalog.md)), 카테고리 공급, 알림 변수 구독, 전환 추적([26](26-tracking.md)) |

### 독립 Plugin (14)

| Plugin | 설명 (manifest) | 규약 사례 |
|---|---|---|
| Banner | 배너 관리 | 블록 타입 등록 + 필터 이벤트 발행([17](17-block-system.md)) |
| Faq | FAQ 관리 | `FaqQueryInterface` 1:1 바인딩([16](16-contract-catalog.md)) |
| Popup | 레이어 팝업 관리 | 데이터 리셋 계약 구현 |
| Qna | 질문과 답변 관리 | 데이터 리셋 계약 구현 |
| Survey | 설문 생성·참여·집계 | 블록 시스템 연동 |
| Widget | 화면 고정 **프론트 위젯** (PC 좌/우, 모바일 하단) | 22장 관리자 대시보드 위젯과 별개 개념 |
| PayApp | 페이앱 결제 연동 | `PaymentGatewayInterface` 구현([16](16-contract-catalog.md)) |
| TestPay | 개발용 가상 결제 (모든 결제 즉시 성공) | production 등록 차단 가드의 모범 사례([16](16-contract-catalog.md)) |
| EmailNotify | 서버 메일 기반 이메일 알림 | `NotificationGatewayInterface` 구현(24장) |
| SendonSms | 센드온 SMS/LMS/MMS 발송 | 〃 |
| SendonTalk | 센드온 카카오 알림톡 발송 | 〃 |
| SnsLogin | 네이버·카카오·Google 소셜 로그인 | 가입 이벤트 체인 연동(19장) |
| MemberPoint | 회원 포인트 적립·차감·내역 | Balance 이벤트 연동(21장) |
| VisitorStats | 서버 사이드 방문자 통계 수집·분석 | `SiteContextReadyEvent` 구독·`TrackingKeys` 사용, `PageViewedEvent`와의 경계(26장) |

### 종속 Plugin (1)

| 활성 키 | 설명 | 규약 사례 |
|---|---|---|
| `Board/BoardReport` | 게시글 신고 | 이 장의 종속 Plugin 레퍼런스 |

## 관련 문서

- `packages/Board/README.md` (공식 Event 표 포함) · `packages/Board/Plugins/BoardReport/README.md`
- [15. Public API](15-public-api.md) · [29. Package Guide](29-package-guide.md) · [30. Plugin Guide](30-plugin-guide.md)
