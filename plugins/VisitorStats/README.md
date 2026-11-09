# VisitorStats

Mublo Framework 방문자 통계 플러그인입니다.

## Overview

- 방문/페이지/유입/환경 통계 수집
- 실시간 대시보드
- 캠페인 및 전환 통계 관리

## Dependency

- Mublo Core `>=1.0.0`

## Install

- 관리자 설치/초기화는 플러그인 관리자에서 진행하는 것을 기준으로 합니다.
- 관리자 진입점: `/admin/visitor-stats/dashboard`

## 국가/도시 통계 (선택)

IP 기반 국가·도시 통계를 쓰려면 아래 두 가지를 직접 준비합니다. 하나라도 없으면 지역 정보만 비어 있고 나머지 통계는 그대로 수집됩니다.

1. 라이브러리 설치: `composer require geoip2/geoip2:^3.3`
2. DB 파일 배치: MaxMind 무료 계정으로 GeoLite2-City를 받아 `storage/geoip/GeoLite2-City.mmdb` 에 둡니다.
   - GeoLite2 EULA상 재배포가 금지되어 있어 저장소·배포본에는 포함하지 않습니다.
   - 정확도 유지를 위해 MaxMind `geoipupdate` 도구로 주기적(월 1회 이상) 갱신을 권장합니다.

- 결과는 환경 분석(국가별·도시별)과 실시간 방문 목록의 "지역" 열에 표시됩니다.
- 설정 이후 새로 들어온 방문부터 기록되며, 기존 로그는 소급되지 않습니다.
- 국가 단위는 신뢰도가 높지만, 국내 모바일 통신사 IP는 도시가 실제와 다르게(예: 서울) 잡히는 경우가 많아 도시 통계는 참고용입니다.

## Main Routes

- Admin
  - `GET /admin/visitor-stats/dashboard`
  - `GET /admin/visitor-stats/realtime`
  - `GET /admin/visitor-stats/pages`
  - `GET /admin/visitor-stats/referrers`
  - `GET /admin/visitor-stats/campaigns`

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 다수의 관리자 통계 API 라우트를 함께 제공합니다.
- 공개 문서에서는 디렉토리명 `VisitorStats`와 표시명 `visitorstats`를 혼용하지 않도록 주의하세요.
- **전환 통계 화면은 AutoForm 플러그인의 `form_submissions` 테이블을 전제**로 합니다.
  AutoForm이 없으면 전환 통계는 빈 상태로 표시되며(소프트 의존 — 오류는 발생하지 않음),
  방문·유입·캠페인 통계는 AutoForm 없이도 정상 동작합니다.
