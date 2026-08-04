# 26. 통계·트래킹

통계·트래킹은 코어 기능이 아니다. 코어는 **표면**만 제공한다 — 페이지뷰 이벤트(`PageViewedEvent`), 세션 키 계약(`TrackingKeys`), 외부 픽셀 전송 유틸리티(`MubloTracking.js`). 방문 수집·집계·통계 화면은 전부 번들 플러그인 **VisitorStats**가 구현하며, Shop 같은 Package는 계약 상수를 통해 전환 시점의 캠페인 키만 기록한다. 이 "코어 표면 + 번들 플러그인" 구조 덕에 통계 구현체를 통째로 끄거나 교체해도 코어는 영향을 받지 않는다.

이 장이 다루는 기능: 방문자 통계 화면·API(대시보드/실시간/페이지/유입/환경/캠페인/전환 + 통계 API 20종·데이터 퍼지, `plugins/VisitorStats/routes.php`), 페이지뷰 발생 통지(`src/Core/Event/Tracking/PageViewedEvent.php`). 함께 사용하는 공개 표면은 `TrackingKeys`(`src/Contract/Tracking/TrackingKeys.php`)와 `MubloTracking.js`(`public/assets/js/MubloTracking.js`)다.

## 개요 — 3층 구조

| 층 | 구성 요소 | 소스 |
|---|---|---|
| 코어 표면 | `PageViewedEvent`, `PageTypeResolveEvent`, `TrackingKeys`, `MubloTracking.js` | `src/Core/Event/Tracking/`, `src/Contract/Tracking/`, `public/assets/js/` |
| 수집·집계·화면 | VisitorStats 플러그인 (수집 Subscriber, Collector, 통계 Service, 관리자 화면 9종) | `plugins/VisitorStats/` |
| 소비 Package | 전환 시점에 세션의 캠페인 키를 자기 데이터에 기록 | `packages/Shop/Controller/Front/CartController.php` |

코어의 책임은 "페이지가 조회되었다"는 사실의 통지와 키 이름의 통일까지다. 무엇을 저장하고 어떻게 집계하며 어떤 화면으로 보여줄지는 코어가 알지 못한다. VisitorStats는 `manifest.json`상 `mandatory: false`인 선택 플러그인이며, 비활성화해도 코어와 다른 확장은 정상 동작한다.

### 책임과 비책임

- **코어가 하는 것**: 프론트 요청 문맥 이벤트 발행(`SiteContextReadyEvent` — `src/Core/App/Application.php`), 렌더링 완료 후 페이지뷰 이벤트 발행(`PageViewedEvent` — `src/Core/Rendering/FrontViewRenderer.php`), 캠페인 세션 키 이름의 계약화(`TrackingKeys`), 외부 픽셀 전송 유틸리티 제공(`MubloTracking.js`).
- **코어가 하지 않는 것**: 방문 데이터 저장, 집계, 통계 화면, 봇 판별, 캠페인 키 관리. 코어에는 통계 테이블이 하나도 없다 — 통계 테이블 8종은 모두 VisitorStats의 마이그레이션이 만든다.
- **VisitorStats가 하지 않는 것**: 다른 확장의 전환 데이터 소유. Shop의 주문 캠페인 키는 `shop_orders`에, 폼 전환은 AutoForm의 `form_submissions`에 있고, VisitorStats는 후자를 읽기 전용으로 참조할 뿐이다.

### 동작 흐름

```text
[유입]   GET /?k=summer2026
           │
           ▼
Application ── SiteContextReadyEvent 발행 (라우팅 직전)
           │
           ▼
VisitorTrackingSubscriber ── 필터 5종(프론트/AJAX/미리보기/정적파일/봇)
           │
           ▼
VisitorCollector.track()
  ├─ 캠페인 키 세션 저장 (TrackingKeys::CAMPAIGN_KEY)
  ├─ 신규 세션 → plugin_visitor_logs INSERT IGNORE + 집계 5종 UV/PV 증분
  └─ 기존 세션 → 집계 PV만 증분
           │
      (페이지 이동 반복 — 캠페인 키는 세션에서 유지)
           │
[전환]   POST 주문 생성 (Shop CartController)
           └─ session.get(TrackingKeys::CAMPAIGN_KEY) → shop_orders.campaign_key
```

이와 별개로 `FrontViewRenderer`는 렌더링 완료 후 `PageViewedEvent`를 발행한다(서드파티 추적 확장 포인트, 아래 참조).

## 페이지뷰 수집

### 코어 표면: PageViewedEvent

`src/Core/Rendering/FrontViewRenderer.php`가 프론트 페이지 조립을 마친 뒤(Foot 출력 직후) 발행한다. payload는 `domainId`, `url`, `pageType`, `memberId`(비로그인 null), `ipAddress`, `userAgent`, `referer`다 (`src/Core/Event/Tracking/PageViewedEvent.php`).

`pageType`은 `FrontViewRenderer::resolvePageType()`이 판별한다 — 블록페이지면 `page`, view path 접두사로 `index`/`auth`/`member`/`search`를 판별하고, 못 하면 `PageTypeResolveEvent`(`src/Core/Event/Rendering/PageTypeResolveEvent.php`)를 발행해 Package/Plugin에 위임한다. 응답이 없으면 `other`다.

**주의 — 실제 수집 경로는 이 이벤트가 아니다.** 현재 번들 VisitorStats는 `PageViewedEvent`가 아니라, 라우팅 직전에 `src/Core/App/Application.php`가 발행하는 `SiteContextReadyEvent`를 구독해 수집한다(`plugins/VisitorStats/Subscriber/VisitorTrackingSubscriber.php`). `PageViewedEvent`는 페이지 타입·회원 ID가 확정된 렌더링 완료 시점의 확장 포인트로 열려 있으며, 현재 저장소 안에 구독자는 없다. 서드파티 추적 플러그인이 붙기 좋은 지점이라는 위치는 유효하다 ([이벤트 시스템](../dev-guide/event-system.md) §2.3).

### VisitorStats의 수집 필터

`VisitorTrackingSubscriber::onSiteContextReady()`는 기록 전에 다섯 가지를 거른다. 모두 소스에서 확인되는 조건이다.

1. 프론트 요청만 (`$context->isFront()` 아니면 제외 — 관리자 화면은 집계되지 않는다)
2. AJAX 요청 제외 (`$request->isAjax()`)
3. 블록 에디터 미리보기 제외 (`is_editor_preview()` — 편집 작업이 통계를 오염시키지 않도록)
4. 정적 파일 확장자 제외 (css/js/이미지/폰트/미디어 등 `EXCLUDED_EXTENSIONS` 23종)
5. 봇 UA 제외 (`UserAgentParser::isBot()` — `bot`, `crawl`, `spider`부터 `gptbot`, `claudebot`까지 패턴 목록 기반. UA가 빈 문자열이어도 봇으로 간주)

수집 실패는 `try/catch`로 삼켜 `error_log`만 남긴다 — 통계가 사이트 동작을 방해하지 않는다는 원칙이다.

### VisitorCollector의 UV/PV 처리

`plugins/VisitorStats/Service/VisitorCollector.php`의 `track()`은 세션 기반으로 UV(방문자)와 PV(페이지뷰)를 구분한다.

- 세션에 `visitor_tracked_{오늘날짜}` 마킹이 있으면 **PV만 증분**하고 끝낸다.
- 신규 세션이면 UA 파싱(`UserAgentParser` — browser/os/device), 리퍼러 분류(`direct`/`search`/`social`/`external` — 검색엔진·SNS 도메인 목록 매칭, 자기 도메인 유입은 `direct`로 전환), 신규 방문 여부(당일 동일 IP 로그 존재 여부)를 판정해 원본 로그를 `INSERT IGNORE`로 기록한다. `(domain_id, session_id, visit_date)` 고유키라 세션당 하루 1행이다.
- 로그 삽입에 성공한 경우에만 일별·시간대별·페이지별·유입경로별·캠페인별 집계 테이블의 UV를 증분한다.

테이블은 8개다. 7개는 `001_create_visitor_stats_tables.sql`이 만든다 — 원본 로그 `plugin_visitor_logs`, 집계 `plugin_visitor_daily`/`_hourly`/`_pages`/`_referrers`/`_campaigns`, 캠페인 키 설정 `plugin_visitor_campaign_keys`. 나머지 하나는 확장이 통보한 전환을 담는 `plugin_visitor_conversions`(`002_create_conversion_events.sql`)로, 방문 수집과 무관한 별도 경로다(아래 참조). 페이지 URL은 쿼리스트링·프래그먼트를 제거하고 저장한다(`cleanUrl()`).

### MubloTracking.js — 서버 수집과 별개의 외부 픽셀 유틸리티

`public/assets/js/MubloTracking.js`는 Mublo 서버로는 아무것도 보내지 않는다. 사이트 SEO 설정에 등록된 외부 픽셀(GA4·Meta Pixel·카카오 픽셀·네이버 애널리틱스)로 전환 이벤트를 중계하는 클라이언트 유틸리티다. 픽셀 SDK 로드와 페이지뷰 픽셀 발화는 프레임 스킨 `views/Front/frame/basic/Head.php`가 담당하고, `MubloTracking.js`도 같은 파일에서 `defer`로 로드된다.

```js
MubloTracking.trackConversion('purchase', { value: 50000, currency: 'KRW' });
// type: 'lead' | 'purchase' | 'signup' | 'booking'
```

각 픽셀의 SDK 전역 함수(`gtag`, `fbq`, `kakaoPixel`, `wcs_do`)가 있을 때만 전송하며, 동시에 `window`에 `mublo:conversion` CustomEvent를 발행해 외부 JS(히트맵, AB 테스트 등)가 구독할 수 있게 한다. 즉 서버 사이드 방문 통계(VisitorStats)와 클라이언트 사이드 광고 픽셀 전환은 서로 독립된 두 경로다.

## 캠페인·전환 추적

### 유입: 캠페인 키의 세션 기록

관리자가 캠페인 키 설정 화면에서 키를 만들면 `https://도메인/?k={키}` 형태의 URL을 배포한다(`plugins/VisitorStats/views/Admin/CampaignSettings.php`). 방문자가 이 URL로 들어오면 `VisitorTrackingSubscriber`가 쿼리 파라미터 `k`를 읽어 `VisitorCollector`에 넘기고, Collector는 이를 세션에 저장한다.

```php
// plugins/VisitorStats/Service/VisitorCollector.php
if ($campaignKey !== null && $campaignKey !== '') {
    $this->session->set(\Mublo\Contract\Tracking\TrackingKeys::CAMPAIGN_KEY, $campaignKey);
} else {
    $campaignKey = $this->session->get(\Mublo\Contract\Tracking\TrackingKeys::CAMPAIGN_KEY);
}
```

이후 페이지를 이동해도 세션에서 복원되므로, 유입 후 몇 페이지를 거쳐 전환하든 최초 유입 캠페인이 유지된다.

### 전환: TrackingKeys 계약으로 Package가 기록

세션 키 이름 `visitor_campaign_key`는 `src/Contract/Tracking/TrackingKeys.php`의 상수 `TrackingKeys::CAMPAIGN_KEY` 하나로 통일된다. 플러그인(기록)과 Package(소비)가 문자열 리터럴을 각자 들고 있으면 한쪽의 오타·변경만으로 추적이 조용히 끊기므로, 코어가 중립 계약으로 키 이름을 소유한다 — [16장](16-contract-catalog.md)의 Contract 카탈로그에 속하는 설계다. Shop의 주문 생성이 실제 소비 예다.

```php
// packages/Shop/Controller/Front/CartController.php
use Mublo\Contract\Tracking\TrackingKeys;

$orderPayload = [
    // ...
    'campaign_key' => $this->session->get(TrackingKeys::CAMPAIGN_KEY),
];
```

`shop_orders` 테이블에는 `campaign_key VARCHAR(100)` 컬럼과 인덱스가 있어(`packages/Shop/database/migrations/008_shop_orders.sql`) 주문이 어느 캠페인 유입에서 발생했는지 남는다. VisitorStats는 Shop에 대해 아무것도 모르고, Shop도 VisitorStats 클래스를 참조하지 않는다 — 둘의 접점은 계약 상수 하나뿐이다.

### ConversionRecordedEvent — 폼 밖의 전환을 담는 중립 계약

`src/Contract/Tracking/`에는 캠페인 키 상수 외에 전환 사실을 알리는 중립 계약이 하나 더 있다.

- `ConversionRecordedEvent` — `domainId`·`sourceType`·`sourceId`·`campaignKey`·`status`·`memberId`·`valueAmount`·`currency`·`occurredAt` 등을 담은 readonly 이벤트. 각 소스 서비스가 발행한다.
- `ConversionSourceTypes` — 소스 타입 상수(`rental_order`, `rental_consultation`, `member_signup`)와 관리자 표시 라벨. 문자열 표기 불일치를 막는 장치다.

발행자는 전환이 일어난 사실만 알리고 누가 받는지 모른다. VisitorStats가 설치되지 않은 환경에서는 구독자가 없어 조용히 무시된다 — 발행 측에 방어 코드가 필요 없다는 뜻이다.

수신은 `plugins/VisitorStats/Subscriber/ConversionRecorderSubscriber.php`가 한다. 규칙 네 가지가 계약의 실질이다.

- **멱등** — `(domain_id, source_type, source_id)` UNIQUE. 같은 사건이 재시도·웹훅 중복으로 두 번 와도 한 건으로 수렴하고, 상태가 바뀌면(결제완료 → 취소) 마지막 통보로 갱신된다.
- **신원 없는 전환은 버린다** — `sourceType`·`sourceId`가 비면 멱등키가 성립하지 않으므로 익명 행을 쌓지 않는다.
- **시각은 갈음한다** — `occurredAt`이 없거나 형식이 깨졌으면 수신 시각을 쓴다. 통계에서 빠지는 것보다 근사한 시각으로 남는 편이 낫다.
- **실패는 삼킨다** — 기록 실패가 주문·상담 트랜잭션을 깨뜨리면 안 된다(`error_log`만 남긴다). VisitorStats의 방문 수집과 같은 태도다.

### 전환 통계 화면의 데이터 원천 — 두 저장소

전환 화면은 **소유자가 다른 두 저장소**를 나란히 보여 준다. 합치지 않는 것이 요점이다.

| 원천 | 소유자 | 담기는 것 | 없을 때 |
|---|---|---|---|
| `form_submissions` | AutoForm 확장 | 폼 접수·성공 여부 | `isAvailable()`이 false → 폼 전환 영역이 빈 상태 |
| `plugin_visitor_conversions` | VisitorStats | 확장이 통보한 전환(주문·상담·가입 등) | 통보된 전환이 없으면 "소스별 전환" 카드를 감춘다 |

앞쪽은 남의 테이블을 읽기 전용으로 참조하는 소프트 의존이고(`plugins/VisitorStats/Repository/ConversionRepository.php`), 뒤쪽은 이 플러그인이 소유한 테이블이다(`ConversionEventRepository`). 조회 API도 각각 `POST /admin/visitor-stats/api/conversion-stats`와 `POST /admin/visitor-stats/api/event-conversions`로 갈라져 있어, AutoForm이 없어도 후자는 정상 동작한다.

## 통계 화면

VisitorStats의 모든 라우트는 `AdminMiddleware`를 거치는 관리자 전용이다(`plugins/VisitorStats/routes.php`). 진입점은 `/admin/visitor-stats/dashboard`.

| 화면 | 라우트 | 내용 |
|---|---|---|
| 대시보드 | `/admin/dashboard` | 방문 요약·추이 |
| 실시간 | `/admin/realtime` | 최근 5분 접속·오늘 UV/PV·최근 로그 30건 |
| 페이지별 분석 | `/admin/pages` | 페이지 URL별 UV/PV |
| 유입 경로 | `/admin/referrers` | direct/search/social/external 유형·도메인별 |
| 환경 분석 | `/admin/environment` | 브라우저·OS·디바이스 분포 |
| 캠페인 통계 | `/admin/campaigns` | 캠페인 키별 방문·추이 |
| 전환 목록·통계 | `/admin/conversions`, `/admin/conversion-stats` | form_submissions 기반 전환 |
| 캠페인 키 설정 | `/admin/campaign-settings` | 키 CRUD + 배포 URL 복사 |

화면은 껍데기(ViewResponse)만 렌더하고 데이터는 POST API 20종(`/admin/api/summary` ~ `/admin/api/purge`)으로 비동기 조회한다. 이 외에 관리자 대시보드 위젯(`plugins/VisitorStats/Dashboard/VisitorStatsWidget.php`)과 프론트 블록 콘텐츠 타입 2종(`visitor_stats` 숫자형, `visitor_trend` 그래프형 — 매 방문 값이 바뀌므로 `noCache`, `plugins/VisitorStats/VisitorStatsProvider.php`)도 등록한다.

## 확장 개발자 규약

**내 Package의 전환에 캠페인 키를 남기려면** — Shop과 같은 패턴을 쓴다. `TrackingKeys::CAMPAIGN_KEY`로 세션을 읽어 자기 테이블의 컬럼(관례상 `campaign_key VARCHAR(100)`)에 저장하면 된다. 세션 키 문자열을 직접 쓰지 말 것 — 계약 상수가 유일한 접점이다.

**내 화면의 조회를 방문 통계에 넣으려면** — 별도 등록이 필요 없다. 프론트 요청이면 `SiteContextReadyEvent` 시점에 자동 수집된다. 단, 페이지 타입 분류(`PageViewedEvent`의 `pageType`)까지 정확히 하려면 `PageTypeResolveEvent`를 구독해 자기 view path에 맞는 타입을 반환한다. Board Package가 `board_list`/`board_view`를 이렇게 공급한다(`packages/Board/Subscriber/PageTypeSubscriber.php`).

**자체 추적 플러그인을 만들려면** — 수집 시점이 이르고 가벼워야 하면 `SiteContextReadyEvent`, 페이지 타입·회원 확정 후여야 하면 `PageViewedEvent`를 구독한다. 수집 실패를 삼키는 VisitorStats의 방어 패턴(try/catch + error_log)을 따를 것.

```php
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Tracking\PageViewedEvent;

class MyTrackingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [PageViewedEvent::class => 'onPageViewed'];
    }

    public function onPageViewed(PageViewedEvent $event): void
    {
        try {
            // $event->getUrl(), getPageType(), getMemberId(), getIpAddress() ...
        } catch (\Throwable $e) {
            error_log('[MyTracking] failed: ' . $e->getMessage());
        }
    }
}
```

구독자 등록은 Provider의 `boot()`에서 한다 — VisitorStats가 `VisitorStatsProvider::boot()`에서 `EventDispatcher::addSubscriber()`로 등록하는 방식 그대로다([12장](12-plugin.md)).

### Best Practice / Anti Pattern

- **권장**: 캠페인 키 접근은 반드시 `TrackingKeys::CAMPAIGN_KEY` 상수로. 전환 기록 컬럼은 `campaign_key VARCHAR(100)`으로 통일(Collector가 100자로 절단해 저장하므로 길이가 맞아야 조인이 어긋나지 않는다).
- **권장**: 추적 구독자 안에서 예외를 밖으로 던지지 말 것. 통계는 부가 기능이며, 수집 실패가 페이지 응답을 깨면 안 된다.
- **금지**: `Mublo\Plugin\VisitorStats\` 네임스페이스의 Service·Repository를 다른 확장에서 직접 참조하는 것. 플러그인 내부 구현은 공개 API가 아니다([15장](15-public-api.md)). VisitorStats가 비활성화되면 해당 클래스는 로드되지 않는다.
- **금지**: 세션 키 문자열 `'visitor_campaign_key'`를 리터럴로 쓰는 것. 계약 상수를 우회하면 키 변경 시 추적이 조용히 끊긴다.

## 경계 — Public / Internal

| 표면 | 지위 |
|---|---|
| `PageViewedEvent`, `PageTypeResolveEvent`, `SiteContextReadyEvent` | 공식 Event — 확장이 구독해도 되는 공개 표면 |
| `TrackingKeys`, `ConversionSourceTypes`, `ConversionRecordedEvent` | 공개 Contract (16장 카탈로그 소속) |
| `MubloTracking.js` | 공개 클라이언트 라이브러리 (28장 소속) |
| `VisitorCollector`, `VisitorStatsService`, Repository 8종 | VisitorStats 내부 구현 — 외부 참조 금지 |
| `plugin_visitor_*` 테이블 | VisitorStats 소유 — 다른 확장이 직접 읽고 쓰지 않는다 |

## 개인정보 경계

코드로 확인되는 수집·보관 범위는 다음과 같다.

- **원본 로그**(`plugin_visitor_logs`)에는 IP 주소 전체(`VARCHAR(45)`, IPv6 수용), User-Agent(500자 절단), 리퍼러 URL, 랜딩 URL, 회원 ID가 저장된다. 해시·익명화는 적용되지 않는다.
- **집계 테이블** 5종에는 개인 식별 정보가 없다 — 날짜·시간·URL·유형별 카운트뿐이다.
- **보관 기간**: 원본 로그는 관리자 퍼지 API(`apiPurge`, 기본 30일·최소 7일)로 도메인별 삭제한다(`VisitorLogRepository::purgeOldLogs()`). 자동 삭제 스케줄러는 현재 없다 — 보존 기간 준수는 운영자의 수동 조치다.
- **화면 노출**: 전환 목록은 IPv4 마지막 옥텟을 `xxx`로 마스킹해 표시하지만(`ConversionStatsService::maskIp()`), 실시간 화면의 최근 로그는 원본 IP를 그대로 표시한다(`plugins/VisitorStats/views/Admin/Realtime.php`). 두 화면 모두 관리자 전용이다.
- **데이터 초기화**: Provider가 `DataResettableInterface`를 노출하고 `VisitorStatsDataResetter`에 위임해 관리자 데이터 초기화에서 도메인별 통계 테이블 6종을 삭제할 수 있다(캠페인 키 설정 테이블은 통계가 아닌 설정이므로 삭제 대상이 아니다).
- 외부 픽셀 전송(GA4 등)은 사이트 설정에 픽셀 ID를 등록한 경우에만 활성화되며, 그 수집 범위는 각 외부 서비스의 정책을 따른다.

## 관련 문서

- [08장 — Event](08-event.md): EventDispatcher와 Subscriber 등록 규약
- [12장 — Plugin](12-plugin.md): VisitorStats 같은 독립 Plugin의 구조
- [16장 — Contract 카탈로그](16-contract-catalog.md): `TrackingKeys`가 속한 공개 계약 전수
- [17장 — 블록 시스템](17-block-system.md): `visitor_stats`/`visitor_trend` 블록 타입 등록 방식
- [28장 — 클라이언트 JS 라이브러리](28-client-js.md): `MubloTracking.js`의 공개 라이브러리 지위
- [이벤트 시스템](../dev-guide/event-system.md): `PageViewedEvent`·`PageTypeResolveEvent` 발행 지점 상세
