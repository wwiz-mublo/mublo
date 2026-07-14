# VisitorStats Plugin 가이드

> **작성일**: 2026-03-08
> **위치**: `plugins/VisitorStats/`

---

## 1. 개요

VisitorStats는 방문자 통계를 수집·분석하는 플러그인이다.
세션 기반 UV/PV 구분, 캠페인 키 추적, AutoForm 전환 연동을 포함한다.

### 주요 기능
- 방문자(UV)/페이지뷰(PV) 실시간 집계
- 일별/시간대별 추이 차트
- 유입 경로(검색/SNS/외부/직접) 분석
- 브라우저/OS/디바이스 분포
- 페이지별 방문 통계
- 캠페인 키(`?k=xxx`) 추적 + 전환율 분석
- AutoForm 전환(폼 제출) 연동
- 관리자 대시보드 위젯

---

## 2. 디렉토리 구조

```
plugins/VisitorStats/
├── VisitorStatsvider.php          # DI 등록 + boot
├── AdminMenuSubscriber.php           # 관리자 메뉴 등록
├── routes.php                        # 라우트 정의 (7 GET + 27 POST)
├── Controller/
│   └── VisitorStatsController.php    # 메인 컨트롤러 (35개 메서드)
├── Service/
│   ├── VisitorStatsService.php       # 통계 조회/집계
│   ├── VisitorCollector.php          # 실시간 수집 (세션 기반)
│   ├── ConversionStatsService.php    # 전환 분석
│   └── UserAgentParser.php           # UA 파싱 (브라우저/OS/디바이스)
├── Repository/
│   ├── VisitorLogRepository.php      # 방문 로그 (원본)
│   ├── VisitorDailyRepository.php    # 일별 집계
│   ├── VisitorHourlyRepository.php   # 시간대별 집계
│   ├── VisitorPageRepository.php     # 페이지별 통계
│   ├── VisitorReferrerRepository.php # 유입 경로 통계
│   ├── VisitorCampaignRepository.php # 캠페인 통계
│   ├── VisitorCampaignKeyRepository.php # 캠페인 키 CRUD
│   └── ConversionRepository.php      # 전환 쿼리 (form_submissions 읽기)
├── Subscriber/
│   └── VisitorTrackingSubscriber.php # SiteContextReadyEvent 리스너
├── Dashboard/
│   └── VisitorStatsWidget.php        # 대시보드 위젯
├── database/migrations/
│   └── 001_create_visitor_stats_tables.sql
├── views/Admin/
│   ├── _nav.php            # 탭 네비게이션 (공유)
│   ├── Dashboard.php       # 대시보드
│   ├── Realtime.php        # 실시간
│   ├── Pages.php           # 페이지 분석
│   ├── Referrers.php       # 유입 경로
│   ├── Environment.php     # 환경 (브라우저/OS/디바이스)
│   ├── Campaigns.php       # 캠페인 통계
│   ├── CampaignSettings.php # 캠페인 키 설정
│   ├── Conversions.php     # 전환 목록
│   ├── ConversionStats.php # 전환 통계
│   └── Install.php         # 설치
└── assets/js/
    └── visitor-stats.js    # Canvas 차트 (line/bar/donut)
```

---

## 3. 데이터베이스 (7개 테이블)

| 테이블 | 용도 | 유니크 키 |
|--------|------|----------|
| `plugin_visitor_logs` | 방문 로그 원본 (30일 보존) | (domain_id, session_id, visit_date) |
| `plugin_visitor_daily` | 일별 집계 (영구) | (domain_id, visit_date) |
| `plugin_visitor_hourly` | 시간대별 집계 | (domain_id, visit_date, visit_hour) |
| `plugin_visitor_pages` | 페이지별 통계 | (domain_id, visit_date, page_url) |
| `plugin_visitor_referrers` | 유입 경로 통계 | (domain_id, visit_date, referer_type, referer_domain) |
| `plugin_visitor_campaigns` | 캠페인별 통계 | (domain_id, visit_date, campaign_key) |
| `plugin_visitor_campaign_keys` | 캠페인 키 설정 | (domain_id, campaign_key) |

### 주요 컬럼

**plugin_visitor_logs**:
- `session_id`, `member_id`, `ip_address`, `user_agent`
- `browser`, `os`, `device` — UserAgentParser가 파싱
- `referer_url`, `referer_domain`, `referer_type` — direct/search/social/external
- `landing_url`, `campaign_key`, `is_new`
- `visit_date`, `visit_hour`, `created_at`

**plugin_visitor_daily**:
- `total_visitors`, `total_pageviews`, `new_visitors`, `return_visitors`
- `member_visitors`, `guest_visitors`
- `pc_visitors`, `mobile_visitors`, `tablet_visitors`

---

## 4. 데이터 수집 흐름

```
프론트 페이지 요청
    ↓
SiteContextReadyEvent 발생
    ↓
VisitorTrackingSubscriber.onSiteContextReady()
    │ 조건 체크: 프론트 전용, AJAX 제외, 정적 파일 제외, 봇 제외
    ↓
VisitorCollector.track()
    ↓
VisitorLogRepository.insertIgnore()
    │ UK (domain_id, session_id, visit_date) 로 중복 방지
    ↓
    ├─ 새 세션 (UV): 모든 집계 테이블 incrementVisitor()
    │   ├─ DailyRepository (방문자+PV+디바이스 플래그)
    │   ├─ HourlyRepository (방문자+PV)
    │   ├─ PageRepository (방문자+PV)
    │   ├─ ReferrerRepository (유형별)
    │   └─ CampaignRepository (캠페인 키 있을 때)
    │
    └─ 기존 세션 (PV only): incrementPageview()만
```

### UV/PV 구분
- **세션 플래그**: `visitor_tracked_{날짜}` — 하루 단위 UV 구분
- 플래그 없으면 새 방문(UV) → 모든 카운터 증가
- 플래그 있으면 재방문(PV) → 페이지뷰만 증가

### 제외 조건
- AJAX 요청
- 정적 파일: css, js, png, jpg, gif, svg, webp, avif, ico, woff, woff2, ttf, eot, map, xml, json, mp3, mp4, webm, pdf, zip, gz
- 봇: 28+ 패턴 (Googlebot, FacebookBot, GPTBot, ClaudeBot 등)

---

## 5. 캠페인 키 시스템

### 동작 원리

```
1. 관리자: 캠페인 키 생성 (예: summer-event)
2. 외부 링크: https://example.com/landing?k=summer-event
3. 방문자 클릭 → VisitorTrackingSubscriber가 ?k= 파라미터 감지
4. 세션에 저장: visitor_campaign_key = summer-event
5. 이후 페이지뷰에서도 캠페인 귀속 유지 (세션 기반)
6. 폼 제출 시 → form_submissions.campaign_key에 저장 (전환 추적)
```

### 캠페인 키 설정

| 필드 | 설명 |
|------|------|
| `campaign_key` | 고유 키 (영문, 숫자, `-`, `_`) |
| `group_name` | 그룹명 (선택, 예: "2026년 여름 프로모션") |
| `memo` | 메모 (선택) |
| `is_active` | 활성 여부 |

### 관리자 UI
- `/admin/visitor-stats/campaign-settings` — 키 생성/수정/삭제
- `/admin/visitor-stats/campaigns` — 캠페인별 방문자/PV/전환/전환율 테이블

---

## 6. 전환 추적 (AutoForm 연동)

### 연결 구조

```
AutoForm                              VisitorStats
────────                              ────────────
form_submissions                      ConversionRepository (읽기 전용)
├── campaign_key  ←── 세션에서 저장     ├── getConversionsByCampaign()
├── form_id       ←── forms 조인       ├── getConversionsByForm()
├── ip_address    ←── 마스킹 표시      ├── getConversionList()
└── created_at    ←── 기간 필터        └── getDailyConversions()
```

- **ConversionRepository**: AutoForm의 `form_submissions` 테이블을 읽기 전용으로 조회
- **ConversionStatsService**: 방문자 데이터(VisitorCampaignRepository) + 전환 데이터를 결합
- **전환율**: `전환 수 / 방문자(UV) × 100`
- **IP 마스킹**: 마지막 옥텟을 `xxx`로 치환 (개인정보 보호)

### 전환 관련 뷰

| 뷰 | 경로 | 내용 |
|----|------|------|
| 전환 목록 | `/admin/visitor-stats/conversions` | 필터(기간/폼/캠페인) + 페이지네이션 |
| 전환 통계 | `/admin/visitor-stats/conversion-stats` | 요약 카드 + 일별 추이 + 캠페인별/폼별 테이블 |
| 대시보드 | `/admin/visitor-stats/dashboard` | 전환 카드 (전기 대비 변화율) |
| 캠페인 목록 | `/admin/visitor-stats/campaigns` | 전환/전환율 컬럼 포함 |

---

## 7. 관리자 뷰 (10개 + 설치)

### 네비게이션 탭

```
대시보드 | 실시간 | 페이지 | 유입경로 | 캠페인 | 전환 목록 | 전환 통계 | 환경
```

### 뷰별 설명

| 뷰 | API | 차트/표시 |
|----|-----|----------|
| **대시보드** | summary, trend, hourly, environment, dashboard-conversions | 요약 카드 4개 + 일별 추이 + 시간대별 + 디바이스 도넛 |
| **실시간** | realtime (5초 폴링) | 현재 접속자, 오늘 UV/PV, 최근 30건 로그 |
| **페이지** | pages | 페이지별 PV/UV 테이블 (페이지네이션) |
| **유입경로** | referrers | 유형별 도넛 + 도메인별 테이블 |
| **캠페인** | campaign-summary | 캠페인별 방문자/PV/전환/전환율 + 그룹 요약 |
| **전환 목록** | conversions | 필터(기간/폼/캠페인) + 페이지네이션 |
| **전환 통계** | conversion-stats | 요약 카드 + 일별 추이 + 캠페인별/폼별 |
| **환경** | environment | 브라우저/OS/디바이스 도넛 3개 |

### 기간 선택 옵션
- 오늘 / 최근 7일(기본) / 최근 30일 / 이번 달

### 전기 대비 변화율
- 선택 기간과 동일 길이의 이전 기간을 비교
- 예: 최근 7일 → 그 전 7일과 비교

---

## 8. API 엔드포인트 (27개 POST)

모든 API는 `POST`, 관리자 인증 필수, URL 접두사 `/admin/visitor-stats/`.

### 통계 API

| 엔드포인트 | 파라미터 | 응답 |
|------------|----------|------|
| `api/summary` | period | visitors, pageviews, newVisitors, change{} |
| `api/trend` | period | [{date, visitors, pageviews, newVisitors}] |
| `api/hourly` | period | [{hour, visitors, pageviews}] (24항목) |
| `api/realtime` | (없음) | recentCount, todayVisitors, todayPageviews, logs[] |
| `api/pages` | period, page? | items[], totalItems, currentPage, totalPages |
| `api/referrers` | period | byType[], topDomains[] |
| `api/environment` | period | browser[], os[], device[] |
| `api/campaigns` | period | items[], groups{} |
| `api/campaign-trend` | period, campaign_key? | [{date, visitors, pageviews}] |
| `api/purge` | (없음) | deleted (30일 이전 로그 삭제) |

### 캠페인 키 CRUD

| 엔드포인트 | 파라미터 | 동작 |
|------------|----------|------|
| `api/campaign-key/create` | campaign_key, group_name?, memo? | 생성 |
| `api/campaign-key/update` | key_id, group_name?, memo?, is_active? | 수정 |
| `api/campaign-key/delete` | key_id | 삭제 |

### 전환 API

| 엔드포인트 | 파라미터 | 응답 |
|------------|----------|------|
| `api/campaign-summary` | period | items[] (방문자+전환+전환율), totalVisitors, totalConversions, totalRate |
| `api/conversion-stats` | period | total, avgDaily, topCampaign, topForm, dailyTrend[], byForm[], byCampaign[] |
| `api/conversions` | period, form_id?, campaign_key?, page? | items[], totalItems, currentPage, totalPages, forms[] |
| `api/form-conversions` | form_id, period | total, campaignTotal, campaignRate, byCampaign[] |
| `api/dashboard-conversions` | period | conversions, change |

---

## 9. 서비스 계층

### VisitorStatsService — 통계 조회

| 메서드 | 용도 |
|--------|------|
| `getSummary(domainId, period)` | 방문자/PV/신규 요약 + 전기 대비 변화율 |
| `getTrend(domainId, period)` | 일별 추이 (빈 날짜 0으로 채움) |
| `getHourly(domainId, period)` | 24시간 분포 (기간 합산) |
| `getRealtime(domainId)` | 최근 5분 접속자, 오늘 통계, 최근 로그 |
| `getPages(domainId, period, page, perPage)` | 페이지별 PV/UV (페이지네이션) |
| `getReferrers(domainId, period)` | 유입 유형별 + 상위 도메인 |
| `getEnvironment(domainId, period)` | 브라우저/OS/디바이스 분포 |
| `getCampaigns(domainId, period)` | 캠페인별 통계 + 그룹 요약 |
| `periodToDates(period)` | 기간 문자열 → [시작일, 종료일] |

### VisitorCollector — 데이터 수집

| 메서드 | 용도 |
|--------|------|
| `track(domainId, ip, ua, uri, referer, memberId, siteDomain, campaignKey?)` | 방문 기록 + 5개 집계 테이블 갱신 |
| `parseReferer(referer, siteDomain)` | 유입 분류 (direct/search/social/external/internal) |

### ConversionStatsService — 전환 분석

| 메서드 | 용도 |
|--------|------|
| `getCampaignSummary(domainId, period)` | 캠페인별 방문자+전환+전환율 |
| `getConversionStats(domainId, period)` | 전체 전환 통계 (요약+추이+폼별+캠페인별) |
| `getConversionList(domainId, period, formId?, key?, page, perPage)` | 전환 목록 (IP 마스킹) |
| `getFormConversions(domainId, formId, period)` | 폼별 캠페인 전환 분석 |
| `getDashboardConversions(domainId, period)` | 대시보드 전환 카드 (전기 비교) |

### UserAgentParser — UA 분석

| 메서드 | 용도 |
|--------|------|
| `parse(ua)` | `{browser, os, device}` 반환 |
| `isBot(ua)` | 봇 여부 판별 (28+ 패턴) |
| `detectBrowser(ua)` | edge/samsung/opera/firefox/ie/chrome/safari/other |
| `detectOs(ua)` | ios/android/windows/mac/linux/other |
| `detectDevice(ua)` | tablet/mobile/pc |

---

## 10. 차트 시스템 (visitor-stats.js)

외부 라이브러리 없이 Canvas API로 직접 구현한 차트 모듈.

| 메서드 | 용도 |
|--------|------|
| `VisitorChart.lineChart(canvasId, data, options)` | 라인 차트 (다중 시리즈) |
| `VisitorChart.barChart(canvasId, data, options)` | 막대 차트 |
| `VisitorChart.donutChart(canvasId, data, options)` | 도넛 차트 |
| `VisitorChart.donutLegend(data, options)` | 도넛 범례 HTML |
| `VisitorChart.formatNum(n)` | 숫자 포맷 (천 단위 콤마) |
| `VisitorChart.colors` | 색상 팔레트 (9색) |

### 사용 예시

```javascript
VisitorChart.lineChart('vs-trend-chart', data, {
    height: 220,
    labelKey: 'date',
    series: [
        { key: 'visitors', label: '방문자', color: VisitorChart.colors.primary },
        { key: 'pageviews', label: '페이지뷰', color: VisitorChart.colors.success },
    ]
});
```

---

## 11. 대시보드 위젯

| 항목 | 값 |
|------|-----|
| **ID** | `plugin.visitor_stats` |
| **제목** | 오늘 방문자 |
| **슬롯** | 2 (기본) |
| **표시** | 방문자(UV) + PV + 신규방문 + 전일 대비 변화율 |

---

## 12. 유입 경로 분류

| 유형 | 조건 | 예시 |
|------|------|------|
| `direct` | referer 없음 또는 자체 도메인 | 직접 URL 입력, 북마크 |
| `search` | 7개 검색엔진 도메인 | google, naver, daum, bing, yahoo, zum, duckduckgo |
| `social` | 10개 SNS 도메인 | facebook, instagram, twitter/x, youtube, tiktok, kakaostory, band... |
| `external` | 위에 해당 없는 외부 도메인 | 블로그, 뉴스 사이트 등 |

---

## 13. 데이터 보존 정책

| 데이터 | 보존 | 비고 |
|--------|------|------|
| 방문 로그 (`plugin_visitor_logs`) | 30일 | `apiPurge()`로 정리 |
| 일별 집계 (`plugin_visitor_daily`) | 영구 | 용량 작음 (1행/일/도메인) |
| 시간대별 집계 | 영구 | 24행/일/도메인 |
| 페이지별/유입경로/캠페인 | 영구 | 페이지 수에 비례 |
| 캠페인 키 설정 | 영구 | CRUD |

---

## 14. 관련 문서

- `.claude/plans/conversion-tracking/overview.md` — 전환 추적 전체 계획서
- `docs/conversion-tracking/conversion-views.md` — 전환 뷰 시스템 문서
- `docs/conversion-tracking/mublo-tracking.md` — MubloTracking.js 문서
- `plugins/AutoForm/docs/MUBLO_FORM_MASTER_PLAN.md` — AutoForm 기획서
