# Widget

Mublo Framework 화면 고정 위젯 플러그인입니다.

## Overview

- 좌/우 고정 위젯 관리
- 모바일 하단 아이콘형 위젯 지원
- 프론트 활성 위젯 API 제공

## Dependency

- Mublo Core `>=1.0.0`

## Install

- 설치 라우트: `POST /admin/widget/install`
- 관리자 진입점: `/admin/widget/list`

## Main Routes

- Admin
  - `GET /admin/widget/list`
  - `POST /admin/widget/config/save`
  - `POST /admin/widget/store`
  - `POST /admin/widget/sort`
- Front API
  - `GET /widget/api/active`

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 프론트 렌더링은 활성 위젯 API를 통해 연동됩니다.
