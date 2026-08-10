# Changelog

이 프로젝트의 주요 변경 사항을 기록합니다.

형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따릅니다.

## [Unreleased]

### Added
- 에디터: `data-toolbar-mobile`·`data-toolbar-items-mobile`·`data-toolbar-breakpoint`로 모바일 툴바를 따로 구성할 수 있습니다. Board 기본·갤러리 글쓰기는 768px 이하에서 필수 편집 버튼만 표시합니다
- 에디터: 툴바 프리셋에 `compact`(7개 — 되돌리기·되돌리기 취소·굵게·기울임·밑줄·링크·이미지)를 추가했습니다. 기존 `minimal`(3개)과 `basic`(20개) 사이가 비어 있어 좁은 화면이나 좁은 칸에 쓸 프리셋이 없었습니다. 320px 폭에서 한 줄에 들어가며, `toolbar` 와 `toolbarMobile` 양쪽에서 쓸 수 있으므로 스킨이 버튼 이름을 나열하지 않아도 됩니다

### Changed
- **VisitorStats**: 전환 통계가 폼 확장의 테이블(`form_submissions`·`forms`)을 직접 조회하지 않고, `ConversionRecordedEvent` 로 통보된 전환만 집계합니다. 전환의 갈래는 계약이 실어 온 `sourceType`·`sourceLabel` 로 구분하므로 주문·상담·가입·폼 접수가 한 화면에서 같은 축으로 보입니다. 이에 따라 "폼별 전환"은 "소스별 전환"이 되고, 전환 목록의 필터도 폼 선택에서 소스 선택으로 바뀌며, 목록에서 IP 열이 빠집니다(이벤트 계약에 없는 값입니다). 관리자 API 응답도 함께 바뀌었습니다 — `/api/conversion-stats` 는 `byForm`·`topForm` 대신 `bySource`·`topSource` 를, `submissions` 대신 `recorded` 를 돌려주고, `/api/conversions` 는 요청 필드가 `form_id` → `source_type` 이며 항목이 `submission_id`·`created_at`·`form_name`·`ip_address` 대신 `conversion_id`·`occurred_at`·`source_type`·`source_label`·`status` 를 담습니다
- Core: 전환 소스 타입(`ConversionSourceTypes`)은 **코어 자신의 개념만** 담습니다. 확장이 발행하는 타입은 그 확장이 소유하며 코어에 등재하지 않습니다. 등재는 강제가 아니어서 어떤 문자열이든 그대로 집계되고, 표시 이름은 발행 쪽이 `ConversionRecordedEvent::$sourceLabel` 로 실어 보내는 값이 우선합니다 — 한 타입 안의 갈래(폼 제목·상품군 등)는 이 값으로 구분하며, 통계 화면도 타입+라벨 단위로 나눠 집계합니다

### Removed
- **VisitorStats**: 폼 확장의 테이블을 직접 읽던 `ConversionRepository` 를 제거했습니다. 이 저장소는 `form_submissions` 존재 여부만 확인하고 `forms` 는 확인하지 않은 채 조인했으며, `outcome = 'success'` 같은 컬럼·값 계약까지 남의 스키마에 의존하고 있었습니다. 해당 확장이 없는 설치본에서는 전환 화면이 언제나 0 이었고, 미설치와 실적 0 건이 화면에서 구분되지도 않았습니다
- Core: `ConversionSourceTypes` 에서 특정 확장의 소스 타입 상수 `RENTAL_ORDER`·`RENTAL_CONSULTATION` 를 제거했습니다. 코어가 확장 목록을 들고 있으면 그 확장이 없는 설치본에서 죽은 이름이 되고, 확장이 이름을 바꿔도 코어는 알 수 없습니다. **저장된 데이터와 집계는 영향을 받지 않습니다** — 와이어 값(`rental_order` 등)은 그대로이고, 표시 이름은 기록 시점에 이미 행에 저장되어 있습니다. 이 상수를 참조하던 확장은 자기 상수를 정의하거나 문자열 리터럴로 바꾸고, 표시 이름은 `ConversionRecordedEvent::$sourceLabel` 로 실어 보내세요
- **VisitorStats**: 관리자 API `/api/form-conversions` 와 `/api/event-conversions` 를 제거했습니다. 앞의 것은 어떤 화면도 호출하지 않는 경로였고, 뒤의 것은 전환 통계가 하나로 합쳐지면서 `/api/conversion-stats` 응답의 `bySource` 로 흡수되었습니다
- Core: 알림 템플릿 헬퍼(`NotificationTemplateUiHelper`)에서 폼 확장의 테이블(`forms`·`form_fields`)을 직접 조회하던 `loadActiveFormsWithMeta()`·`buildExampleBodies()`·`buildSamplePreviewValues()` 를 제거했습니다. 코어가 특정 확장의 스키마를 알고 있던 자리이며, 안정 계약(`NotificationTemplateContextInterface`)에 노출된 적이 없고 호출하는 곳도 없었습니다. 확장이 제공하는 알림 변수는 이전부터 `CollectNotificationVariablesEvent` 를 구독해 각 확장이 채워 넣는 경로로 흐르고 있으므로 동작은 달라지지 않습니다. 이 구체 클래스를 직접 참조해 세 메서드를 부르던 코드가 있다면 해당 이벤트 구독으로 옮기세요
- Core: 보안 파일 다운로드 권한 검증에서 `autoform` 파일 카테고리 하드코딩을 제거했습니다. 코어가 확장의 카테고리 이름을 알던 분기로, 확장용 위임 경로(`SecureFileAccessEvent`)가 이미 있는데도 그 앞을 가로채고 있었습니다. 이벤트를 거쳐도 grant 하는 구독자가 없으면 안전 기본값인 관리자 전용으로 판정하므로 접근 허용 범위는 그대로이며, 거부 시 남는 경고 로그 문구만 달라집니다. 이 카테고리를 관리자 외에게 열려면 `SecureFileAccessEvent` 를 구독해 grant 하세요

## [1.1.0] - 2026-08-07

### Added
- Core: 회원 공개 식별자 `public_id` 를 도입했습니다. 22자 소문자 16진수이며, 타인을 클라이언트에서 가리키는 HTML·URL·JSON 은 이제 내부 `member_id` 나 로그인 아이디 대신 이 값을 씁니다. 기존 회원은 마이그레이션이 값을 채워 넣습니다
- Core: 남에게 보이는 이름을 만드는 `publicDisplayName()` 을 `AuthenticatedUser` 와 `MemberProfile` 에 추가했습니다. 닉네임이 없으면 `회원 {public_id 앞 12자}` 로 떨어지며, 실명이나 로그인 아이디로는 떨어지지 않습니다
- Core: 확장이 회원 문맥 액션(쪽지 보내기·프로필 보기 같은 작성자 메뉴 항목)을 등록할 수 있는 Contract 를 추가했습니다 — `MemberActionQueryInterface`, `MemberActionDefinition`, `MemberActionView`, `MemberActionScope`, `MemberActionVariant`, `MemberActionTargetTransport`, `MemberActionStateResolverInterface`, `MemberActionStateScope`, `MemberExtensionIdentity`, 그리고 `MemberActionBuildingEvent`. 소비자는 구현 클래스나 URL 을 알지 않으며, 액션을 등록한 확장을 비활성화하면 메뉴에서 사라집니다
- Core: 작성자 메뉴를 렌더링하는 뷰 헬퍼 `memberActionMenu($actions, $targetPublicId, $options)` 와 컴포넌트 자산을 추가했습니다
- Core: 원장·잔액 기반 랭킹 조회 계약 `BalanceRankingQueryInterface` 와 부속 타입(`BalanceRankingEntry`, `BalanceRankingFilter`, `BalanceRankingMetric`, `BalanceRankingPage`)을 추가했습니다
- 관리자: 시스템 관리 화면에 회원 액션 정의 진단 패널이 추가되어, 확장이 등록한 액션의 문제를 도메인별로 확인할 수 있습니다

### Changed
- **Board 스킨**: 글·댓글 데이터에서 `member_id` 가 빠지고 `author_public_id` 와 `author_actions` 가 제공됩니다. 작성자 동작은 `$this->memberActionMenu($article['author_actions'], $article['author_public_id'], [...])` 조합으로 렌더링해야 합니다. 내부 회원 번호나 로그인 아이디로 프로필·쪽지 URL 을 직접 조립하던 커스텀 스킨은 수정이 필요합니다. 번들 스킨(basic·gallery)은 이미 반영되어 있으며 자세한 내용은 `SKIN-GUIDE.md` 를 참고하세요
- Core: `MemberQueryInterface` 에 `findByPublicId()`, `searchActiveByNickname()`, `publicIdsFor()` 가 추가되었습니다. 이 인터페이스를 직접 구현한 확장은 세 메서드를 함께 구현해야 합니다
- Core: 회원 검색이 닉네임만 대상으로 합니다. 로그인 아이디로는 다른 회원을 찾을 수 없습니다
- Shop: 리뷰·문의 작성자 표시가 닉네임이 없을 때 로그인 아이디로 떨어지던 동작을 없애고 공개 표시명을 사용합니다
- Qna: 문의 작성자·운영자 표시에 공개 표시명을 사용합니다
- Board·Shop·Qna: 기존 글·댓글·리뷰·문의에 저장된 작성자 표시 데이터를 마이그레이션이 공개 표시명으로 정리합니다. 로그인 아이디가 노출되어 있던 과거 데이터가 함께 정정됩니다
- 프론트: 마이페이지 기본 레이아웃이 사용자 정보를 `AuthenticatedUser` 에서 직접 읽도록 정리되었습니다
- 관리자: 블록 에디터의 스크립트를 뷰 인라인에서 정적 자산(`assets/js/admin/blockeditor.js`)으로 분리했습니다. 브라우저가 캐시하므로 편집 화면 재방문이 빨라집니다. 이 뷰를 직접 수정해 쓰던 경우 스크립트 위치가 바뀐 점에 유의하세요
- 관리자: 포인트 내역 화면의 총 건수 조회가 빨라졌습니다. 원장에 `(domain_id, created_at, member_id, amount)` 인덱스를 추가해 인덱스만으로 집계합니다 (100만 행 기준 110.6ms → 19.5ms)
- Board: 게시판 목록과 총 건수 조회가 빨라졌습니다. `(domain_id, board_id, status, is_notice, created_at)` 커버링 인덱스를 추가하고, 공지 여부가 이미 필터로 고정된 목록에서 중복 정렬을 제거했습니다 (20만 행 기준 목록 8.9ms → 3.5ms, 건수 14.9ms → 3.3ms)
- 업그레이드: 이 릴리즈는 마이그레이션을 포함합니다 — Core `026`(회원 `public_id`)·`027`(원장 인덱스)·`028`(메뉴 제공자명 정정), Board `004`(작성자 표시명 백필)·`005`(목록 인덱스), Shop `030`, Qna `003`. **기존 설치본은 파일을 교체해도 마이그레이션이 자동으로 적용되지 않습니다.** 업데이트 후 관리자 → 시스템 관리에서 대기 중인 마이그레이션을 실행하세요
- 업그레이드: Core `026` 이 회원 `public_id` 를 채운 뒤에 Board·Shop·Qna 의 작성자 표시명 백필이 의미를 갖습니다. 시스템 관리 화면의 실행 순서를 그대로 따르면 됩니다

### Deprecated
- Core: `MemberIdentity::getUserId()` — 다음 major 에서 제거합니다. 신규 코드는 `getPublicId()` 와 `getDisplayName()` 을 사용하세요. 기존 안정 이벤트의 하위 호환을 위해 당분간 유지됩니다

### Fixed
- 메뉴: 확장이 만든 메뉴 항목의 제공자명이 소문자로 저장되던 문제를 수정했습니다. 그 탓에 메뉴 관리에서 항목을 수정할 때 제공자가 선택되지 않았고, 마이페이지 사이드바가 확장 아이콘 대신 기본 아이콘을 표시했습니다. 데이터베이스 조회는 대소문자를 구분하지 않아 다른 기능은 정상 동작했기에 드러나지 않던 문제입니다. 마이그레이션이 기존 데이터도 함께 정정합니다

## [1.0.1] - 2026-08-05

### Fixed
- 메뉴: 같은 메뉴를 자기 하위에 넣거나 최대 깊이(10단계)를 넘기는 트리 저장을 거부합니다. 이전에는 그대로 저장되어 경로가 어긋났습니다
- 메뉴: 메뉴명을 변경할 때 하위 경로의 이름이 일부만 갱신되던 문제를 수정했습니다. 경로명을 경로 코드에서 다시 만들므로 이미 어긋나 있던 기존 데이터도 함께 정리됩니다
- 메뉴: 메뉴명 변경을 하나의 트랜잭션으로 묶어, 중간에 실패해도 브레드크럼에 옛 이름이 남지 않습니다
- Board: 글 개별 권한 레벨이 명시적으로 지정되지 않으면 게시판 설정을 상속합니다. 이전에는 빈 값이나 숫자가 아닌 값이 `0`(비회원 허용)으로 저장되어, 회원 전용으로 설정한 게시판인데도 그 글만 비회원에게 열리는 일이 생길 수 있었습니다. 판단할 수 없는 입력은 이제 상속으로 되돌아갑니다
- Board: 글 개별 레벨로 게시판 설정보다 권한을 낮추는 것은 관리자만 가능합니다. 조이는 방향은 그대로 누구나 할 수 있습니다. 스킨이 작성 폼에서 레벨을 입력받더라도 일반 회원이 게시판 정책을 우회할 수 없습니다
- Board: 관리자 화면이 게시판 설정과 글 개별 설정의 차이를 드러냅니다. 글 폼의 "게시판 설정 사용" 옵션에 게시판의 현재값을 함께 표시하고, 개별 설정이 게시판보다 낮은 글은 목록과 폼에서 경고로 표시합니다. 이전에는 "게시판 설정 사용"과 "Lv.0 (전체)"이 화면에서 구분되지 않았습니다
- Board: 다운로드 권한이 없는 첨부도 클릭을 받고, 이유를 모달로 알려줍니다. 비회원에게는 "로그인 후 다운로드 가능합니다"와 함께 로그인 버튼을(로그인 후 원래 글로 복귀), 레벨이 모자란 회원에게는 권한 없음을 알립니다. 이전에는 회색 글씨로 반응 없이 남아 있어 링크가 고장 난 것인지 권한이 모자란 것인지 알 수 없었습니다 (basic·gallery 스킨)
- Board: 다운로드 주소로 직접 접근했을 때 브라우저에 JSON 원문이 그대로 보이던 문제를 수정했습니다. 비회원은 로그인 페이지로, 권한이 없는 회원은 403 페이지로, 없는 첨부는 404 페이지로 보냅니다. 스크립트 요청(XHR·`Accept: application/json`)은 이전처럼 JSON 을 받되 상태 코드가 401/403/404 로 구분됩니다
- Board: 첨부 다운로드 주소를 스킨이 조립하지 않고 `download_url` 을 그대로 출력하도록 바꿨습니다. 식별자 규칙이 바뀔 때마다(예: 마이그레이션 003 의 `attachment_id` → `public_id`) 갱신되지 않은 스킨이 조용히 404 가 되던 문제를 끊습니다. 커스텀 스킨은 `SKIN-GUIDE.md` §2-3 을 따라 `download_url` 로 교체해 주세요. 주소를 만들 수 없는 첨부는 깨진 링크 대신 "받을 수 없는 첨부입니다" 로 표시됩니다
- Board: 게시글 보기에서 첨부파일 다운로드 링크가 동작하지 않던 문제를 수정했습니다. 스킨에 전달되는 첨부 데이터에서 `public_id` 가 누락되어 링크 주소가 잘린 채 만들어졌고, 그 결과 다운로드 횟수도 오르지 않고 "다운로드 N" 표시도 나타나지 않았습니다 (basic·gallery 스킨 공통)
- 프론트: 403·404 에러 페이지가 컨트롤러가 넘긴 구체적 사유를 버리고 일반 문구만 보여주던 문제를 수정했습니다. "게시판을 찾을 수 없습니다", "글쓰기 권한이 없습니다" 처럼 무엇이 문제인지 화면에 나옵니다. 사유가 없으면 종전 문구 그대로입니다
- 이벤트: 이벤트 디스패처가 부모 클래스·인터페이스 이름으로 등록한 리스너를 실행하지 않던 문제를 수정했습니다. 세분화된 서브클래스로 발행되는 이벤트를 상위 이름으로 구독하면 조용히 무시됐고, 예외가 아니라서 로그에도 남지 않았습니다
- MemberPoint: 위 문제로 지급되지 않던 가입 축하 포인트가 정상 지급됩니다
- Shop: 위 문제로 발행되지 않던 LEVEL 트리거 쿠폰 자동발행이 정상 동작합니다
- Manual: 게시판 매뉴얼이 Board 스킨의 `basic` 폴백 동작을 실제와 반대로 설명하던 문제를 수정했습니다. 누락된 파일은 `basic` 의 같은 파일로 대체되며, 다만 그 화면만 톤이 어긋나므로 네 파일을 모두 두는 것을 권장합니다. 스킨 제작 가이드의 Board 스킨 파일 목록에서 빠져 있던 `Password.php` 도 추가했습니다
- Manual: 스킨 제작 가이드에 버전 표식이 없어, 번들 내용을 고쳐도 이미 가져간 도메인에는 전달되지 않던 문제를 수정했습니다. 가져오기를 다시 실행하면 개정본이 반영됩니다
- SnsLogin: 회원 탈퇴 시 SNS 제공자 연결 해제를 탈퇴가 확정된 뒤에 시도합니다. 이전에는 탈퇴 처리 전에 외부 연결을 먼저 끊어서, 이어지는 탈퇴 처리가 실패하면 계정은 그대로인데 SNS 로그인만 끊긴 상태로 남았고 되돌릴 방법이 없었습니다. SNS로 가입해 다른 로그인 수단이 없는 회원은 계정 접근 자체를 잃을 수 있었습니다
- SnsLogin: 제공자 연결 해제 실패가 더 이상 탈퇴를 막지 않습니다. 이전에는 제공자 장애나 관리자의 Client ID 삭제만으로 해당 회원이 탈퇴 자체를 할 수 없었고, 연결이 여럿일 때 중간에 실패하면 앞서 끊긴 연결은 방치됐습니다. 실패한 연결은 '폐기 실패'로 표시해 남기고, 관리자가 SNS 연동 내역에서 재시도할 수 있습니다
- SnsLogin: 관리자가 회원을 삭제할 때도 제공자 연결을 폐기합니다. 이전에는 로컬 연동 기록만 사라지고 제공자 측 연결과 토큰은 그대로 남아 추적할 수 없었습니다
- 확장: 일부 확장의 버전 표기가 실제와 달라 관리자 화면에 잘못 표시되던 것을 바로잡았습니다

## [1.0.0] - 2026-08-04

### Added
- Core: 멀티 도메인 컨텍스트 시스템, DI 컨테이너, 이벤트 디스패처, Contract 시스템
- Core: 라우팅, 인증/세션, 데이터베이스 추상화, 블록 기반 페이지 빌더
- Core: 웹 설치기 (6단계 — 환경 체크, DB, 도메인, 보안, 관리자, 완료)
- Core: 관리자 대시보드 및 사이트 운영 기능
- Core: 확장 호환성 검사·서명 검증·마이그레이션 체크섬·데이터 초기화 계약
- Core: 시작 킷, 사이트맵, 알림, 정책 개정 이력, 블록 개정 이력 및 복구 기능
- Package: Board — 게시판, 댓글, 카테고리, 리액션, 포인트 연동
- Package: Shop — 상품, 장바구니, 주문, 결제, 쿠폰, 배송, 리뷰
- Plugin(Board): BoardReport — 게시글 신고 접수와 블라인드 처리. 패키지 종속 플러그인의 레퍼런스 구현
- Plugin: VisitorStats — 서버사이드 방문자 통계, 대시보드 위젯
- Plugin: MemberPoint — 회원 포인트 적립/차감, 이벤트 기반 자동 지급
- Plugin: Banner — 이미지 배너 관리, 스케줄 표시, 블록 연동
- Plugin: Widget — 고정 위치 위젯 (PC 좌/우, 모바일 하단)
- Plugin: Popup — 레이어 팝업 (이미지/HTML, 반응형, 디바이스별 표시)
- Plugin: Survey — 설문 작성/수집/결과 집계, 블록 연동
- Plugin: Faq — FAQ 카테고리/항목 관리
- Plugin: Qna — Q&A 질문과 답변 관리
- Plugin: SnsLogin — 소셜 로그인 (네이버, 카카오, 구글)
- Plugin: Manual — 관리자·프론트 매뉴얼 책/페이지와 블록 콘텐츠
- Plugin: TestPay — 개발·테스트용 가상 결제. 모든 결제가 즉시 성공한다
- Plugin: PayApp — 페이앱 결제 연동 (카드/휴대폰/카카오페이/네이버페이/가상계좌)
- Plugin: EmailNotify — 코어 Mailer 기반 이메일 알림 발송, 도메인별 템플릿 관리
- Plugin: SendonSms — 센드온 SMS/LMS/MMS 발송 (도메인별 API 연동)
- Plugin: SendonTalk — 센드온 API 기반 카카오 알림톡 발송

[Unreleased]: https://github.com/wwiz-mublo/mublo/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/wwiz-mublo/mublo/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/wwiz-mublo/mublo/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/wwiz-mublo/mublo/releases/tag/v1.0.0
