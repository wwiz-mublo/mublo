# Banner

Mublo Framework 배너 관리 플러그인입니다.

## Overview

- 관리자 배너 CRUD
- 노출 순서 정렬
- 블록 에디터 연동용 배너 목록 제공

## Dependency

- Mublo Core `>=1.0.0`

## Install

- 설치 라우트: `POST /admin/banner/install`
- 관리자 진입점: `/admin/banner/list`

## Main Routes

- Admin
  - `GET /admin/banner/list`
  - `GET /admin/banner/create`
  - `POST /admin/banner/store`
  - `POST /admin/banner/sort`

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 블록 연동은 `/admin/banner/block-items`를 사용합니다.
