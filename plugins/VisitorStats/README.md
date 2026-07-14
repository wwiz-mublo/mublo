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
