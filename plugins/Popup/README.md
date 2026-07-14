# Popup

Mublo Framework 레이어 팝업 관리 플러그인입니다.

## Overview

- 이미지/HTML 팝업 관리
- 반응형 및 디바이스별 노출
- 프론트 활성 팝업 목록 API 제공

## Dependency

- Mublo Core `>=1.0.0`

## Install

- 설치 라우트: `POST /admin/popup/install`
- 관리자 진입점: `/admin/popup/list`

## Main Routes

- Admin
  - `GET /admin/popup/list`
  - `GET /admin/popup/create`
  - `POST /admin/popup/store`
  - `POST /admin/popup/sort`
- Front API
  - `GET /popup/api/active`

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 프론트 렌더링은 활성 팝업 API를 통해 연동됩니다.
