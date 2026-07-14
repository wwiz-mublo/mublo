# 확장(패키지·플러그인) 필수사항

이 문서는 튜토리얼이 아니라 **규범 문서**다. [패키지 만들기](package-development.md)와
[플러그인 만들기](plugin-development.md)가 "만드는 법"을 다룬다면, 이 문서는
"어기면 다른 도메인·에디터·제거 과정이 깨지는 것들"을 등급을 매겨 정리한다.

배포 커뮤니티에 확장을 공개하려는 개발자는 이 문서를 체크리스트로 사용한다.
공식 등록 심사 기준도 이 문서를 따른다.

## 등급 표기

| 등급 | 의미 |
|------|------|
| **MUST** | 위반 시 보안 사고, 데이터 파손, 타 확장·코어 오동작. 심사 반려 사유. |
| **SHOULD** | 위반해도 동작은 하지만 사용자 경험·생태계 일관성이 깨진다. 정당한 이유가 있을 때만 예외. |
| **MAY** | 선택. 지원하면 더 좋은 것들. |

---

## 1. 도메인 격리

Mublo 는 하나의 설치본이 여러 도메인(사이트)을 서빙한다. 격리 위반은
**한 사이트의 데이터가 다른 사이트에 노출되는 보안 사고**다.

- **MUST** — 도메인별 데이터를 담는 모든 테이블에 `domain_id` 컬럼을 두고,
  모든 SELECT/UPDATE/DELETE 쿼리에 `domain_id` 조건을 건다. 예외는
  진짜 전역 데이터(시스템 설정 등)뿐이며, 그 경우 확장 문서(README)에
  "전역 데이터를 다루므로 운영자는 `super_only: true` 소비를 검토하라"고
  안내한다 (운영자 플래그는 개발 단계에서 설정하지 않는다 —
  [Manifest 기준](manifest-standard.md) 참조).
- **MUST** — `domain_id` 를 클라이언트 입력(폼 hidden, 쿼리스트링)에서 받지
  않는다. 항상 `Context` 에서 얻는다.
- **MUST** — 클라이언트가 보낸 **대상 ID(글·상품 등)로 조치를 만들기 전에
  그 대상이 현재 도메인 소속인지 검증**한다. 자기 테이블의 domain_id
  스코프만으로는 부족하다 — 다른 도메인의 ID 로 쓰기를 만들면 격리 노출은
  없어도 고아 데이터가 생긴다. (실사례: 블라인드 조치가 글의 도메인 소속을
  확인하지 않아 다른 도메인 글 ID 로 무해한 고아 행을 만들 수 있었다.)
  Package 공개 API가 전역 접근 대상으로 명시한 데이터는 예외로 허용할 수
  있지만, 쓰기 권한을 별도로 검사하고 조치 데이터는 현재 도메인에 귀속해야 한다.
- **MUST** — 파일 업로드 경로를 도메인별로 분리한다
  (예: `/storage/{확장명}/{domain_id}/...`).
- **SHOULD** — `DomainCreatedEvent` 를 구독해 신규 도메인에 기본 데이터를
  시딩하고, 도메인 삭제 대응이 필요하면 관련 이벤트를 확인한다.

## 2. 생명주기 — 설치·제거·초기화

"설치는 되는데 제거하면 잔해가 남는" 확장은 배포 자격이 없다.

- **MUST** — 스키마 변경은 전부 `database/migrations/` 의 번호순 SQL 파일로
  한다. 코드에서 임의로 `CREATE TABLE`/`ALTER TABLE` 을 실행하지 않는다.
  마이그레이션은 MigrationRunner 가 `schema_migrations` 에 이력을 남긴다.
- **MUST** — 이미 배포된 마이그레이션 파일을 수정하지 않는다. 변경은 항상
  새 번호 파일로 추가한다.
- **MUST** — `CREATE TABLE IF NOT EXISTS` + `utf8mb4_unicode_ci` + InnoDB.
  테이블명 `{prefix}` 플레이스홀더는 쓰지 않는다(접두사 시스템 없음).
- **SHOULD** — `InstallableExtensionInterface` 를 구현해 최초 활성화 시딩과
  비활성화 정리를 명시한다. 비활성화는 DB 데이터를 보존한다(재활성화 대비).
- **SHOULD** — `DataResettableInterface` 를 구현해 관리자 데이터 초기화
  화면에 자기 카테고리를 제공한다. Provider는 전용 Resetter에 위임하고,
  Resetter는 생성자 DI를 사용하며 반드시 해당 `domain_id` 만 지운다.
- **MUST** — 확장이 포인트 원장을 남겼다면 데이터 초기화에서 코어
  `BalanceResetGatewayInterface`에 정확한 `source_type`·`source_name`을 전달해
  원장과 회원 잔액 스냅샷을 함께 정합화한다. `balance_logs`를 직접 수정하지 않는다.
- **MUST** — 파일을 함께 지우는 초기화는 `DataResetFilesystemInterface`를 구현하고
  `resetFiles()`에서 처리한다. DB 작업을 수행하는 `reset()`에서 파일을 삭제하지 않는다.

## 3. 데이터베이스 규약

- **MUST** — bool 값을 PDO 에 바인딩하기 전에 int(0/1)로 캐스팅한다.
  PDO 는 `false` 를 빈 문자열로 바인딩해 INT NOT NULL 컬럼 INSERT 가
  실패한다. (실사례: 블록 킷 페이지 적용 실패의 원인이었다.)
- **MUST** — 쿼리는 전부 프리페어드 스테이트먼트. 문자열 연결로 값을
  넣지 않는다.
- **SHOULD** — JSON 컬럼을 읽을 때 빈 값·배열·객체를 모두 방어한다.
  PHP 의 빈 배열은 `json_encode` 시 `[]`(배열)가 되므로, 객체를 기대하는
  소비자에게 넘길 때는 `(object)` 캐스팅으로 `{}` 를 보장한다.
  (실사례: 빈 `content_config` 가 `[]` 로 직렬화돼 에디터에서 HTML 저장이
  유실됐다.)
- **SHOULD** — 설정류 컬럼의 기본값 처리는 "없으면 기본값" 패턴으로
  일관되게: `($config['key'] ?? '') ?: 'default'`. undefined key 접근은
  경고가 예외로 승격되는 환경에서 렌더 전체를 죽인다.

## 4. 블록 시스템 접점

블록 콘텐츠 타입을 제공하는 확장이 지켜야 할 것들.
등록 방법·스킨 구조·블록 킷 호환 지침은 [블록 시스템 개발](block-system.md)을 본다.

### 렌더 마커 (블록 에디터 매핑 계약)

블록 에디터는 프론트 렌더 HTML 의 마커로 클릭을 행/칸에 매핑한다.
코어가 생성하는 래퍼를 스킨이 깨면 그 블록은 에디터에서 편집할 수 없다.

- **MUST** — 코어가 출력하는 행 섹션(`.block-section--{rowId}`)과 칸 래퍼
  (`#bc-{columnId}`) 구조를 스킨이 제거·중복 생성하지 않는다. 스킨은 이
  래퍼들 **안쪽**의 HTML 만 책임진다.
- **MUST** — 칸의 안쪽 여백은 코어가 칸 설정값을 `.block-body` 에 주입한다.
  스킨 루트 요소에 자체 padding 을 하드코딩하지 않는다 — 운영자가 칸
  설정에서 여백을 바꿔도 반영되지 않는 "죽은 설정"이 된다.
- **SHOULD** — 제목은 코어 제목 파셜(`.block-title`)을 사용한다. 에디터의
  "제목 클릭 → 제목 탭 직행" 동선이 이 클래스에 걸려 있다.

### 타입 등록 플래그

블록 에디터와 행 폼은 타입이 `BlockRegistry::registerContentType()` 의
`options` 로 선언한 능력만 UI 로 노출한다. 플래그를 빠뜨리면 기능이
있어도 운영자에게 보이지 않는다.

- **MUST** — 아이템 선택(`content_items`)을 쓰는 타입은 `hasItems: true` 를
  선언한다. 아이템 피커와 에디터의 출력갯수(PC/MO) 설정이 이 플래그를 따른다.
- **MUST** — 출력 스타일(`pc/mo_style`·`cols`·슬라이드 옵션)을 렌더러가
  해석하는 타입은 `hasStyle: true` 를 선언한다. 반대로 FAQ 아코디언처럼
  스타일 개념이 없는 타입은 선언하지 않는다 — 죽은 설정 UI 를 노출하지
  않기 위한 것이므로, 이 생략은 의도를 주석으로 남긴다.

### 콘텐츠 아이템 공급

칸에 "무엇을 보여줄지" 고르는 방식은 두 갈래다. 확장은 자기 타입이
어느 쪽인지 명확히 하고 그 계약을 지킨다.

- **목록형 (스칼라 ID 아이템)** — 게시판·FAQ·메뉴처럼 `content_items` 에
  ID 배열을 저장하는 타입.
  - **MUST** — `BlockContentItemsCollectEvent` 를 구독해 후보 목록
    (`id` + `label`)을 공급한다. 이게 있어야 일반 아이템 피커(체크 목록)와
    블록 에디터의 "표시할 내용 고르기"가 동작한다.
- **전용 선택형 (객체형 아이템)** — 쇼핑몰 상품처럼 `content_items` 에
  객체 배열을 저장하고 자체 선택 UI 를 쓰는 타입.
  - **MUST** — ConfigForm 으로 칸 설정 모달 **콘텐츠 탭**에 전용 셀렉터를
    제공한다. 블록 에디터는 객체형 아이템 칸의 클릭을 이 모달로 직행시킨다
    (`object_items` 자동 감지) — 전용 셀렉터가 없으면 운영자는 내용을
    바꿀 방법이 없다.
- **MUST** — 데이터가 없을 때(선택된 게시판이 삭제됐다든가) 렌더러가
  예외를 던지지 말고 자체 빈 상태 HTML 을 반환한다. 행 하나의 폭사가
  페이지 전체 렌더를 깨뜨린다.

### 캐시

- **MUST** — 블록에 표시되는 원본 데이터가 바뀌는 쓰기 경로에서 블록
  캐시를 무효화한다(도메인 단위 무효화 서비스 사용). "글은 썼는데 메인의
  최신글 블록은 어제 것"이 대표 증상이다.

## 5. 에디터·미리보기 호환

- **MUST** — 프론트에 자동 개입하는 확장(팝업, 방문자 통계, 트래킹 스크립트,
  진입 이벤트류)은 `is_editor_preview()` 가 true 면 개입하지 않는다.
  블록 에디터 미리보기는 실제 프론트 페이지를 iframe 으로 띄우므로,
  이를 무시하면 관리자의 편집 행위가 통계에 오염되고 팝업이 편집을 가린다.

  ```php
  public function onFrontRender($event): void
  {
      if (is_editor_preview()) {
          return;
      }
      // ...
  }
  ```

- **SHOULD** — 스크롤 애니메이션(AOS 류)처럼 편집 화면에서 방해되는 효과도
  같은 게이트를 태운다.

## 6. UI 규약

관리자 화면의 일관성은 개별 확장이 아니라 사용자의 것이다.

- **MUST** — 네이티브 `alert()` / `confirm()` / `prompt()` 를 쓰지 않는다.
  `MubloRequest.showAlert(message, type)`, `showConfirm(message, onConfirm, options)`,
  `showToast()` 를 사용한다.
- **MUST** — 관리자 AJAX 는 `MubloRequest` 를 경유한다(CSRF·에러 처리·알림이
  일괄 적용된다). fetch 를 직접 쓰는 경우에도 CSRF 토큰과 에러 알림 규약을
  동일하게 지킨다.
- **MUST** — 관리자 메뉴는 `AdminMenuBuildingEvent` 로만 등록한다. 메뉴
  code 는 자동 프리픽스(`P_{Name}_`, `K_{Name}_`)를 신뢰하고 코어 대역
  (`001`~)을 침범하지 않는다.
- **MUST** — 정적 에셋은 `/serve/plugin/{Name}/...`, `/serve/package/{Name}/...`
  경로로 참조한다. `plugins/`·`packages/` 디렉토리는 웹루트가 아니다.
- **SHOULD** — 관리자 UI 문구는 초보 운영자 기준으로 쓴다. 블록 에디터가
  노출하는 영역(타입 이름, 설정 라벨)은 특히 — "인스펙터" 같은 개발자
  용어를 피하고, 여백 입력처럼 형식이 있는 값은 placeholder 로 예시를 준다.

## 7. 보안

- **MUST** — 운영자·회원이 입력한 HTML 은 저장 전에 `HtmlSanitizer` 를
  통과시킨다. 프로파일을 용도에 맞게 고른다:

  | 프로파일 | 메서드 | 용도 |
  |----------|--------|------|
  | `rich` | `sanitizeEditorContent()` | 에디터 본문 (게시글 등) |
  | `basic` | `sanitizeBasic()` | 폼 HTML 필드 (iframe 불가) |
  | `block` | `sanitizeForBlock()` | 블록 HTML (레이아웃 CSS 허용) |

- **MUST** — 관리자 라우트에는 `AdminMiddleware` 를 건다. 컨트롤러 안에서
  "어차피 메뉴에 없으니까"는 보호가 아니다.
- **MUST** — 쓰기(POST) 라우트는 CSRF 검증을 우회하지 않는다.
- **MUST** — 파일 업로드는 확장자·MIME 화이트리스트로 검증하고, 실행
  가능 파일을 저장 경로에 두지 않는다. 비공개 파일 다운로드 권한은
  `SecureFileAccessEvent` 로 위임받는다.
- **MUST** — API 키·시크릿은 도메인별 설정으로 저장하고, 클라이언트
  (JS/HTML)로 내려보내지 않는다. 외부 API 호출은 서버가 중계한다.

## 8. 호환성·의존

- **MUST** — manifest 의 `requires.core` 를 실제 검증된 최소 버전으로
  선언한다. 형식은 [Manifest 기준](manifest-standard.md)을 따른다.
- **MUST** — 다른 확장의 클래스를 직접 참조하지 않는다. 다른 확장의
  데이터가 필요하면 Contract(`ContractRegistry::resolve`)로, 시점 개입이
  필요하면 Event 로 한다. Contract 미바인딩(해당 확장 비활성)을 항상
  방어한다.
- **SHOULD** — 코어 이벤트 중 [이벤트 시스템](event-system.md)이 안정으로
  분류한 것만 구독한다. 코어 내부 성격의 이벤트·클래스에 의존하면 코어
  마이너 업데이트에 깨진다.
- **SHOULD** — 같은 기능의 업체 연동(결제·메시징 등)은 기존 Contract
  인터페이스(`PaymentGatewayInterface` 등)의 1:N 등록으로 참여한다.
  독자 인터페이스를 새로 만들면 소비자(쇼핑몰 패키지 등)가 그 확장을
  알아야 하므로 생태계 호환이 깨진다.

### Package 종속 Plugin

- **MUST** — `packages/{Package}/Plugins/{Plugin}`에 배치하고 Manifest v1의 `parent`와 `requires["package:{Package}"]` version 범위를 선언한다.
- **MUST** — 부모 Package의 `Contract/Extension/*`, `Api/DTO/*`, 공식 `Event/*`만 사용한다. 부모의 Service, Repository, Entity, Helper, Controller와 DB 테이블을 직접 참조하지 않는다.
- **MUST** — Plugin 전용 Migration의 name에는 전체 활성 키를 사용한다. 예: source `plugin`, name `Board/BoardReport`.
- **MUST** — 부모가 같은 도메인에서 비활성·실패 상태이면 Plugin의 Runtime, Route, Asset, install/reconcile이 실행되지 않아야 한다.
- **SHOULD** — capability는 Manifest v1의 호환 확장 필드와 개발 문서에 함께 기록한다. 정식 capability validation과 Manifest v2는 범용 Extension Runtime 도입 전까지 요구하지 않는다.

## 9. 배포 전 체크리스트

커뮤니티에 올리기 전 최종 점검. 전부 위 조항의 요약이다.

- [ ] 모든 쿼리에 `domain_id` 스코프 (§1)
- [ ] 마이그레이션 파일로만 스키마 변경, 배포된 파일 수정 금지 (§2)
- [ ] bool→int 캐스팅, 프리페어드 스테이트먼트 (§3)
- [ ] 블록 타입: 렌더 마커 보존, 아이템 공급 계약, 빈 상태 처리 (§4)
- [ ] 프론트 개입 기능에 `is_editor_preview()` 게이트 (§5)
- [ ] 네이티브 alert/confirm 없음 — 전 코드 검색으로 확인 (§6)
- [ ] HTML 저장 경로 전부 새니타이저 통과 (§7)
- [ ] 관리자 라우트 전부 AdminMiddleware (§7)
- [ ] `requires.core` 선언, 타 확장 직접 참조 없음 (§8)
- [ ] 공식 배포 ZIP은 publisher key로 서명하고 payload checksum 검증 ([확장 ZIP 서명](extension-signing.md))
- [ ] 활성화 → 사용 → 비활성화 → 재활성화 → 데이터 초기화 전체 사이클 테스트 (§2)
- [ ] 두 개 이상의 도메인에서 데이터가 섞이지 않는지 테스트 (§1)

---

[< 이전: 플러그인 만들기](plugin-development.md) | [다음: 테스트 >](testing.md)
